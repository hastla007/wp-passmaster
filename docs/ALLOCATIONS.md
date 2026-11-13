# Kontingente (Holds/Sperren) und Käuferlimits

Modul `PassCaptain\Modules\Allocations` (`src/Modules/Allocations.php`, Dienste in `src/Allocations/`).
Admin: **Pass Captain → Kontingente** (Capability `wpcap_manage_events`, eventbezogen über `Access`).

## Begriffe

| Begriff | Bedeutung |
|---|---|
| **Kontingent** (`kind = hold`) | Benannte Reserve je Termin, optional je Ticketart, z. B. „Künstler 20“, „Sponsor 50“, „Presse 10“. Blockiert `quantity − used` Plätze für den freien Verkauf. Vertrauenswürdige Kanäle können es nutzen. |
| **Sperre** (`kind = kill`) | Dauerhaft blockierte Plätze, z. B. „Technik-Sperre 4“. Nie nutzbar, nie automatisch freigegeben; nur manuell aufhebbar. |
| **offen** | Plätze, die das Kontingent noch blockiert: Sperre = `quantity`, Kontingent = `quantity − used` (genutzte Plätze zählen der Kern als verkauft/reserviert). |
| **öffentliche Kanäle** | Website-Checkout (`native`), WooCommerce (`woocommerce`), Warteliste (`waitlist`) und alle anderen nicht vertrauenswürdigen Quellen. |
| **vertrauenswürdige Kanäle** | `pos` (Abendkasse), `guestlist` (Gästeliste), `admin`; Filter `wpcap_allocation_trusted_sources`. |

Rechnung (je Termin, nur wenn Kapazität begrenzt ist):

```
frei(Ticketart) = min( Kapazität(Ticketart) − belegt(Ticketart) − offen(Kontingente dieser Ticketart),
                       Kapazität(Termin)    − belegt(Termin)    − offen(alle Kontingente des Termins) )
```

`belegt` ist dieselbe Zählung wie im Kern (bezahlte Positionen abzüglich erstatteter + aktive Reservierungen).
Ohne Kapazitätsgrenze (unbegrenzt) haben Kontingente keine Wirkung.

## Kontingent nutzen (Kasse, Gästeliste, Admin)

`Orders::create()` mit vertrauenswürdiger Quelle und `$internal['allocation_id']`:

```php
Orders::create( $payload, array( 'source' => 'guestlist', 'key_namespace' => 'guestlist', 'skip_rate_limit' => true, 'allocation_id' => $hold_id ) );
// Admin-Helfer (Quelle admin):
PassCaptain\Allocations\Holds::create_order( $hold_id, $payload, $internal );
```

- Alle Positionen, die das Kontingent abdeckt (gleicher Termin; bei Ticketart-Kontingenten nur diese
  Ticketart), werden vollständig aus dem Kontingent genommen (`used += Menge`, Zeile in
  `wpcap_alloc_usage`). Reicht es nicht: Abbruch mit „Im Kontingent „X“ sind nur noch N Plätze frei.“
- Ohne `allocation_id` kann der Filter `wpcap_allocation_hold_for_source( 0, $source, $selected, $internal )`
  eine Quelle einem Kontingent zuordnen (z. B. Gästeliste → „Künstler“).
- Ohne Kontingent gelten auch für vertrauenswürdige Kanäle die öffentlichen Plätze (Kontingente werden
  nicht stillschweigend aufgebraucht).
- `sources` am Kontingent beschränkt die Nutzung (z. B. nur `guestlist`); leer = alle vertrauenswürdigen.
- Öffentliche Quellen mit `allocation_id` → „Kontingente können über diesen Verkaufskanal nicht genutzt werden.“

Rückgaben: `wpcap_capacity_released` (`expired`, `cancelled`, `refunded`, `event_cancelled`,
`payment_unallocated`, `tickets_revoked` anteilig) gibt genutzte Plätze ins Kontingent zurück. Ist das
Kontingent bereits freigegeben oder hat es Sitzplätze, gehen sie in den freien Verkauf. Abgelaufene,
noch nicht bereinigte Reservierungen zählen sofort nicht mehr (wie im Kern). Verspätete Zahlung
(`confirm_external_payment`) nimmt die Plätze erneut aus dem Kontingent, sonst gelten die öffentlichen
Plätze (sonst `needs_review`).

## Freigabe

- Manuell: teilweise (`Holds::release( $id, $n )`) oder komplett (`Holds::release( $id )`, Status `released`;
  genutzte Plätze bleiben verkauft).
- Automatisch: `release_hours` Stunden vor Beginn (Vorgabe aus Einstellung „Automatische Freigabe“,
  Standard 48 h, 0 = nie) über den stündlichen Cron `wpcap_cleanup_reservations` (`Holds::auto_release_due()`).
- Jede Freigabe feuert `wpcap_allocation_released( $hold_id, $units, $reason )` und
  `wpcap_capacity_increased( $ticket_type_id, $occurrence_id )` – für die Ticketart des Kontingents bzw. für
  jede aktive Ticketart des Termins (automatische Wartelistenangebote).

## Sitzplatz-Kontingente

Das Saalplan-Modul hat keine eigene Sperr-API. Gesperrte Sitze sind aktive Claims in dessen Claims-Tabelle
mit Markierung `order_id = 0`, `ticket_id = 0`, `status = 'held'`, `expires_at = 9999-12-31 23:59:59`
(Saalplan zeigt sie als belegt, vergibt sie nicht automatisch und gibt sie in den Bereinigungen nie frei);
die Zuordnung steht in `wpcap_alloc_hold_seats`.

- `SeatHolds::add( $hold_id, $seat_ids )`: Sitze sperren (Ticketart-Kontingente nur in passenden
  Bereichen); das Kontingent wächst bei Bedarf auf die Sitzanzahl (Kapazität geprüft).
- `SeatHolds::remove( $hold_id, $seat_ids )`: Sitze freigeben, Kontingent schrumpft entsprechend.
- Freigabe des Kontingents gibt überzählige Sitze frei (neueste zuerst).
- Nutzung durch einen vertrauenswürdigen Kanal: vor der Sitzvergabe (Priorität 4) werden passende Sitze
  des Kontingents gelöst; nach der automatischen Vergabe (Priorität 20) werden die Claims der Bestellung
  auf diese Sitze umgesetzt, nicht übernommene Sitze wieder gesperrt – alles in der Bestell-Transaktion.

## Max. Tickets pro Käufer

Je Event (alle Termine) und/oder je Ticketart (`wpcap_alloc_limits`), über alle Bestellungen (bezahlt
und aktiv reserviert):

- Identität: E-Mail kleingeschrieben (`wpcap_allocation_buyer_email`; Gmail-Punkte/Plus-Adressen mit
  `add_filter( 'wpcap_allocation_normalize_gmail', '__return_true' )`) und, wenn aktiviert, das
  angemeldete WordPress-Konto (`$internal['customer_user_id']` oder aktueller Benutzer bei `native`,
  `woocommerce`, `waitlist`). Jede Identität wird geprüft.
- Meldung: „Pro Person sind höchstens %d Tickets für dieses Event möglich; du hast bereits %d.“ bzw. je
  Ticketart „… der Ticketart „X“ …“.
- Ausnahmen: `pos`, `guestlist`, `admin` (`wpcap_allocation_limit_exempt_sources`); feiner über
  `wpcap_allocation_buyer_limit_applies( $applies, $source, $selected, $internal )`.
- WooCommerce ist über `Orders::create()` abgedeckt (Fehler bricht den Checkout ab).
- Ticketumtausch (Bestellportal, Partner-API): ein Umtausch in eine andere Ticketart zählt als weiteres
  Ticket der Zielart für den Käufer der ursprünglichen Bestellung (`wpcap_reserve_capacity`, Phase
  `exchange`, gleiche gesperrte Käuferzeilen). Umtausch durch Personal (`admin`, `pos`) ist wie der
  Verkauf ausgenommen.
- Event-Karte: Hinweis über die Aktion `wpcap_checkout_form_fields` (`p.wpcap-buyer-limit`).

**Nebenläufigkeit:** In `wpcap_order_creating` (Priorität 6) wird je Event und Identität eine Zeile in
`wpcap_alloc_buyers` mit `INSERT … ON DUPLICATE KEY UPDATE` exklusiv gesperrt (auch neue Zeilen; keine
Gap-Locks, feste Reihenfolge). Die Zeile listet die Bestellungen des Käufers mit Mengen; Bestellungen,
die nach dem Snapshot der Transaktion committet wurden, zählen mit den gespeicherten Mengen, sichtbare
Bestellungen aus den Kerntabellen (abgelaufen/storniert/erstattet zählen nicht).

## Sperrreihenfolge

Ticketarten (ID) → Termin → Kontingente des Termins (ID, `wpcap_reserve_capacity`) → Saalplan-Link →
Sitze/Claims → Käuferzeilen. Admin-Aktionen sperren in derselben Reihenfolge (`Holds::lock_occurrence()`).

## Hooks des Moduls

| Hook | Art | Zweck |
|---|---|---|
| `wpcap_allocation_trusted_sources` | Filter `string[]` | Kanäle, die Kontingente nutzen dürfen. |
| `wpcap_allocation_hold_for_source` | Filter `int $hold_id, string $source, array $selected, array $internal` | Kontingent ohne `allocation_id`. |
| `wpcap_allocation_limit_exempt_sources` | Filter `string[]` | Vom Käuferlimit ausgenommene Kanäle. |
| `wpcap_allocation_buyer_limit_applies` | Filter `bool, $source, $selected, $internal` | Limit für eine Bestellung an/aus. |
| `wpcap_allocation_buyer_email` | Filter `string $email` | Normalisierung der E-Mail. |
| `wpcap_allocation_normalize_gmail` | Filter `bool` (false) | Gmail-Punkte/Plus entfernen. |
| `wpcap_allocation_released` | Action `int $hold_id, int $units, string $reason` | Nach einer Freigabe. |
| `wpcap_capacity_increased` | Action `int $ticket_type_id, int $occurrence_id` | Plätze wieder im freien Verkauf (Warteliste). |

Abbruch-Codes (über `wpcap_checkout_error_messages`): `allocation_invalid`, `allocation_forbidden`,
`allocation_exhausted`, `allocation_buyer_limit`; Kapazität: Kern-Codes `sold_out`/`occurrence_sold_out`.

## Bekannte Grenzen

- `wpcap_reserve_capacity` erhält weder `$internal` noch Käuferdaten. Kontingentnutzung durch
  vertrauenswürdige Kanäle und das Käuferlimit werden deshalb in `wpcap_order_creating` geprüft (noch in
  derselben Transaktion, Kontingentzeilen sind bereits gesperrt). Empfehlung für den Kern:
  `$context['internal']` und `$context['email']` an den Filter übergeben.
- Wählt ein vertrauenswürdiger Kanal bei Kontingent-Nutzung Sitze explizit (`seats` im Payload), werden
  die Claims nicht auf Kontingentsitze umgesetzt; nicht genutzte Kontingentsitze bleiben gesperrt.
- Zurückgegebene Plätze eines Sitzplatz-Kontingents gehen in den freien Verkauf (die Sitze gibt das
  Saalplan-Modul frei), nicht zurück ins Kontingent.
