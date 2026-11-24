# Servicebuchungen (Bootsschule, Kurse, Verleih) und Wartelisten

Servicebuchungen sind Buchungen von **Ressourcen** (Boote, Räume, Guides, Geräte) in **Zeitfenstern**
mit eigener Kapazität – im Unterschied zu Eventtickets, die für einen Termin gelten. Dieses Dokument
beschreibt Datenmodell, Buchungsregeln, Lebenszyklus (Zahlung, Bestätigung, Umbuchung, Stornierung,
No-Show), die Wartelisten (Zeitfenster und Events), Service-Pässe sowie REST-Endpunkte und Tests.

Code: `src/ServiceResources.php` (Stammdaten, Regeln, Kollisionen), `src/ServiceBookings.php`
(Lebenszyklus), `src/ServiceWaitlist.php` (Warteliste Zeitfenster), `src/Waitlists.php`
(Event-Warteliste mit Automatik), `src/Memberships.php` (Pässe), `src/Services/` (Erstattung, REST,
öffentliche Seiten), `src/Modules/Services.php` (Hooks), `src/Admin/ServicesPage.php` (Admin).

## Datenmodell (Schema 3.7.0)

| Tabelle | Neue/geänderte Spalten |
|---|---|
| `wpcap_resources` | `default_duration` (Min.), `buffer_before`/`buffer_after` (Min.), `partner_id` (Veranstalter), `ticket_type_id` (Preis; 0 = kostenlos), `rules` (JSON, siehe unten) |
| `wpcap_service_bookings` | `status` (24 Zeichen), `ticket_ids` (JSON), `amount_minor`, `currency`, `manage_token_hash`, `confirm_by`, `cancel_reason`, `refund_id`, `rebook_count`, `last_action_key`, `pass_use_id`, `checked_in_at`, `noshow_at`, `fee_minor`, `updated_at` |
| `wpcap_service_waitlist` (neu) | Warteliste je Zeitfenster: `slot_id`, `email(_hash)`, `quantity`, `position`, `status`, `offer_token_hash`, `offer_expires_at`, `manage_token_hash`, `confirm_token_hash`, `confirm_expires_at` (Double-Opt-in, DB 3.15.0), `booking_id` |
| `wpcap_waitlist` | `hold_quantity` (reservierte Tickets eines offenen Angebots), `manage_token_hash` (Austragen), `order_id` |
| `wpcap_pass_rules` | `scope` (`event`/`service`/`any`), `resource_id`, `slot_mode` (`slot_free`/`slot_bound`), `expires_at` |
| `wpcap_pass_uses` | `resource_id`, `slot_id`, `booking_id` |

## Dauer, Puffer und Kollisionen

- Jede Ressource hat eine **Standarddauer** (Vorbelegung des Wochengenerators) und **Puffer vorher/nachher**
  (z. B. Bootsübergabe, Reinigung).
- Zwei Zeitfenster derselben Ressource kollidieren, wenn sich `[Beginn − Puffer vorher, Ende + Puffer nachher]`
  überschneidet. Kollidierende Zeitfenster werden beim Anlegen, Bearbeiten, im Wochengenerator (übersprungen)
  und über REST (`POST /resources/{id}/slots`, HTTP 409 `wpcap_service_slot_overlap`) abgelehnt.
  REST-Zeiten `starts_at`/`ends_at`: `JJJJ-MM-TT HH:MM:SS` (UTC) oder ISO 8601 mit Offset (`…+02:00`,
  `…Z`); relative Angaben wie `tomorrow` oder Zeiten ohne Zone im ISO-Format → HTTP 400
  `wpcap_invalid_datetime` mit `data.field`.
  Geschlossene Zeitfenster ohne Buchung zählen nicht.
- Beim Buchen wird zusätzlich geprüft, dass kein überlappendes Zeitfenster derselben Ressource bereits Buchungen
  hat (`wpcap_slot_resource_busy`, bei einem gemeinsamen aktiven Leistungserbringer einer anderen Ressource `wpcap_slot_provider_busy`; schützt Altbestände). Die Ressourcenzeile wird dabei gesperrt, parallele Buchungen
  überlappender Zeitfenster laufen also nacheinander.

## Veranstalter (Partner)

- Eine Ressource gehört optional zu einem Veranstalter (`partner_id`, Standard: der Veranstalter des Events).
- Benutzer:innen, die im Veranstalter-Modul mit dem Veranstalter verknüpft sind, sehen und verwalten unter
  „Ressourcen & Zeitfenster“ **nur dessen Ressourcen** (Zeitfenster, Buchungen, Warteliste, Entscheidungen) –
  auch ohne Event-Zuweisung. Den Veranstalter einer Ressource ändern nur Event-Verantwortliche/Admins
  (protokolliert als `service_resource_partner_changed`).
- Hinweis: Der Menüpunkt verlangt weiterhin `wpcap_manage_events`; Veranstalter-Benutzer:innen brauchen diese
  Capability (z. B. über eine eigene Rolle), ihre Sicht ist dann auf ihre Ressourcen beschränkt.
- Bestätigungsanfragen (siehe unten) gehen an die Kontakt-E-Mail des Veranstalters (sonst an die Admin-Adresse).

## Buchungsregeln je Ressource

Gespeichert als JSON in `wpcap_resources.rules`, im Admin unter „Buchungsregeln“ bearbeitbar. Fehlende Werte
nehmen die Standardwerte an (`ServiceResources::default_rules()`).

| Regel | Standard | Wirkung |
|---|---|---|
| `cutoff_minutes` (Buchungsschluss) | 60 | Keine neue Kundenbuchung später als X Minuten vor Beginn (`wpcap_booking_cutoff`). |
| `opens_days` (Vorlauf) | 365 | Buchbar frühestens X Tage vor Beginn, 0 = unbegrenzt (`wpcap_booking_too_early`). |
| `max_per_customer` / `max_period` | 0 / `day` | Höchstens X Plätze je E-Mail-Adresse und Tag/Woche/Monat (Ortszeit der Ressource) oder für alle künftigen Termine (`total`); 0 = unbegrenzt (`wpcap_booking_customer_limit`). |
| `rebook_deadline_minutes` | 1440 | Kund:innen können bis X Minuten vor Beginn umbuchen (`wpcap_booking_rebook_deadline`). |
| `cancel_deadline_minutes` | 1440 | Kund:innen können bis X Minuten vor Beginn stornieren – mit Erstattung (`wpcap_booking_cancel_deadline`). |
| `price_policy` | `same_price` | Umbuchung nur zum gleichen Preis; `refund_difference` erlaubt günstigere Ziele (Differenz wird erstattet) und teurere Ziele mit Zuzahlung (siehe „Umbuchung mit Zuzahlung“). |
| `requires_confirmation` / `confirmation_hours` | 0 / 24 | Bezahlte Buchungen warten auf die Bestätigung des Anbieters. |
| `noshow_policy` / `noshow_grace_minutes` / `noshow_fee_minor` | `none` / 30 / 0 | No-Show-Regel (siehe unten). |
| `noshow_block_after` / `noshow_block_days` | 0 / 180 | Nach N No-Shows im Rückblickzeitraum keine neuen Buchungen (`wpcap_booking_blocked`). |
| `waitlist_enabled` / `waitlist_offer_minutes` | 1 / 120 | Warteliste für ausgebuchte Zeitfenster, Annahmefrist der Angebote. |

Staff-Buchungen (`actor` `admin`/`system`) sind an Buchungsschluss, Vorlauf und Höchstgrenze nicht gebunden,
an Kapazität und Kollisionen schon.

## Lebenszyklus einer Buchung

```
held ──Zahlung/Bestätigung──► confirmed ──Check-in──► checked_in
  │                 └─(Bestätigung nötig)─► pending_confirmation ──bestätigt──► confirmed
  │                                                  └─abgelehnt/Frist──► declined (+ Erstattung)
  ├─Hold abgelaufen / Bestellung storniert──► expired
  └─storniert──► cancelled (+ Erstattung, falls bezahlt)          confirmed ──kein Check-in──► no_show
```

Kapazität belegen `held` (solange nicht abgelaufen), `pending_confirmation`, `confirmed`, `checked_in`,
`no_show` sowie offene Wartelistenangebote.

1. **Reservieren**: `ServiceBookings::reserve()` (REST `POST /service-slots/{id}/bookings`) legt einen Hold
   (max. 15 Minuten) an und gibt einmalig einen `manage_token` zurück. Idempotent je Schlüssel.
2. **Bezahlte Ressourcen** (`ticket_type_id` > 0): Die Buchung wird mit einer Bestellung dieser Ticketart
   verknüpft (`attach_order`, REST `POST /service-bookings/{id}/attach-order` mit Buchungs-Token und
   Bestellzugang). Die Zahlung (`wpcap_order_paid`) bestätigt die Buchung und verknüpft die Tickets der
   Bestellung (`ticket_ids`, Betrag). Kam die Zahlung erst nach Ablauf des Holds und ist der Platz weg, wird
   die Buchung storniert (`capacity_lost`) und erstattet – nie überbucht. Eine unbezahlt abgelaufene/stornierte
   Bestellung gibt den Hold frei.
3. **Kostenlose Ressourcen**: Bestätigung mit dem Buchungs-Token (`confirm_free`, REST
   `POST /service-bookings/{id}/confirm` oder Buchungsseite `?wpcap_service_booking=ID&token=…`).
4. **Manuelle Bestätigung**: Mit `requires_confirmation` geht eine bezahlte Buchung auf `pending_confirmation`
   mit Frist `confirm_by` (höchstens bis Beginn). Kund:in bekommt „wartet auf Bestätigung“, der Anbieter eine
   Anfrage mit Link. Bestätigen/Ablehnen im Admin (Buchungen des Zeitfensters) oder REST
   `POST /service-bookings/{id}/decide` (`decision` = `confirm`|`decline`). Ablehnung oder Fristablauf
   (Sweep) → `declined` mit vollständiger Erstattung.

### Stornierung

`ServiceBookings::cancel()` – Kund:in (innerhalb der Frist), Staff/Veranstalter (bis Ende des Termins, mit oder
ohne Erstattung) oder System:

- Status `cancelled`, Kapazität sofort frei, Job `service_slot_freed` bietet den Platz der Warteliste an.
- **Erstattung** über das zentrale Erstattungsbuch (`Services\Refunder` → `Refunds::execute()`): Zahlungsanbieter,
  Tickets widerrufen, Gutschrift, Webhooks. Wurde mit Gutschein bezahlt, geht der Gutscheinanteil über die
  Voucher-Bridge an den Gutschein zurück (`wpcap_refund_completed` → `Vouchers\Checkout::refund`).
  Der Freigabe-Schwellwert gilt auch hier (Kundenerstattungen darüber warten auf Freigabe).
- Staff-Stornierung **ohne Erstattung** widerruft die Tickets trotzdem.
- **Idempotent**: Der Erstattungsschlüssel ist `service-booking-{id}`; ein zweiter Aufruf setzt nur eine
  unterbrochene Erstattung fort. Scheitert der Anbieter, bleibt die Buchung storniert, die Erstattung steht auf
  `failed` und der Job `service_booking_refund` wiederholt sie (mit Backoff, nie doppelt ausgezahlt).
- Mail `service_booking_cancelled` (bzw. `service_booking_declined`) mit Erstattungshinweis; Audit-Log.
- Pass-Buchungen (slot-bound) geben die Passnutzung zurück.

### Umbuchung

`ServiceBookings::rebook()` – Kund:in (bis zur Umbuchungsfrist; Buchungsregeln der Zielressource gelten) oder Staff:

- Ziel: offenes, künftiges Zeitfenster derselben Ressource oder einer anderen Ressource desselben Events.
- **Atomar**: Ressourcen- und Zeitfensterzeilen werden in fester Reihenfolge gesperrt, Kapazität und Kollision
  des Ziels geprüft, die Buchung in einer Transaktion verschoben. Bei Fehlern bleibt alles, wie es war.
- **Preis**: gleicher Preis immer; `price_policy = refund_difference` erlaubt günstigere Ziele und erstattet die
  Differenz als Betragserstattung (Schlüssel `service-rebook-{id}-{n}`); teurere Ziele laufen über eine
  Zuzahlung (unten). Mit `same_price`, für Pass-/Gratisbuchungen ohne Bestellung und für eine bereits mit
  Zuzahlung umgebuchte Buchung werden teurere Ziele abgelehnt (`wpcap_rebook_price_higher`).
- Idempotent je `idempotency_key`; Mail `service_booking_rebooked`; Audit-Log; frei gewordener Platz → Warteliste.
- Mögliche Ziele: `ServiceBookings::rebook_options()` (REST `GET …/rebook-options`; teurere Ziele mit
  `supplement_minor` = Zuzahlung).

### Umbuchung mit Zuzahlung (teureres Zeitfenster)

Bei `price_policy = refund_difference` und bezahlter Buchung:

1. `rebook()` legt über `Orders::create()` eine **Zuzahlungsbestellung** an: Ticketart der Zielressource, Menge
   der Buchung, vertrauenswürdige Zeilensumme = Preisdifferenz, Zahlart aus `provider` (REST/Portal-Feld) oder die
   erste aktivierte Zahlart der Währung, Quelle `service_supplement`. Die Zahlung wird gestartet
   (`create_payment()`), die Antwort enthält `supplement` mit `order_id`, `public_id`, `amount_minor`,
   `expires_at` und `payment` (`redirect_url` bzw. `instructions`). Das Portal leitet beim Absenden direkt zum
   Zahlungsanbieter weiter; die Käufer:in erhält außerdem den Portal-Link der Zuzahlungsbestellung.
2. Unter den Sperren (Ressourcen, Zeitfenster, Buchung) hält eine **Zuzahlungszeile** (`status = held`,
   `supplement_for` = Buchung, `order_id` = Zuzahlungsbestellung, Ablauf = Reservierungsende der Bestellung) den
   Platz im Ziel. Die bisherige Buchung behält ihren Platz. Ist das Ziel voll, wird die Bestellung storniert
   (`wpcap_slot_full`). Je Buchung ist nur eine Zuzahlung offen (`wpcap_rebook_supplement_open`; dasselbe Ziel
   erneut → dieselbe Zuzahlung, Zahlung wird neu gestartet). Andere Umbuchungen sind solange gesperrt.
3. **Zahlung** (`wpcap_order_paid` der Zuzahlungsbestellung) → `complete_supplement()`: beide Ressourcen,
   Zeitfenster und Buchungszeilen gesperrt, Buchung atomar ins Ziel verschoben. Sie gehört danach zur
   Zuzahlungsbestellung (deren Tickets der Zielressource ersetzen die alten, die widerrufen werden);
   `origin_order_id`/`origin_minor` merken die erste Zahlung, `amount_minor` = beide Beträge. Die
   Zuzahlungszeile wird `merged`. Mail `service_booking_rebooked`, Audit `service_booking_rebooked` mit
   `supplement_order_id`.
4. **Ablauf ohne Zahlung**: Die Bestellung läuft ab (`wpcap_order_cancelled`), die Zuzahlungszeile wird
   `expired`, nur der Zielplatz wird frei (Warteliste); die Buchung bleibt unverändert.
5. **Verspätete Zahlung**: Ist der Zielplatz noch frei, wird umgebucht; sonst (oder wenn die Buchung inzwischen
   storniert ist bzw. das Ziel nicht mehr offen) wird die Zuzahlungszeile `cancelled`
   (`supplement_capacity_lost`, `supplement_original_inactive`, `supplement_slot_unavailable`) und die
   Zuzahlung vollständig erstattet (Schlüssel `service-supplement-{id}`, Wiederholung über
   `service_booking_refund`). Nie überbucht.
6. **Stornierung** einer so umgebuchten Buchung erstattet die Tickets der Zuzahlungsbestellung und die erste
   Zahlung als Betrag aus der ursprünglichen Bestellung (Schlüssel `service-booking-{id}-origin`).
   **Günstigere Umbuchung** einer so umgebuchten Buchung: Die Differenz wird zuerst aus der
   Zuzahlungsbestellung erstattet, der Rest aus der ursprünglichen Bestellung (Schlüssel
   `service-rebook-{id}-{n}-origin`); `origin_minor` sinkt entsprechend, eine spätere Stornierung erstattet
   nur noch den verbleibenden Teil der ersten Zahlung. Wird die
   Buchung storniert, solange die Zuzahlung offen ist, endet diese mit, und ihre Bestellung wird storniert.

### Check-in und No-Show

- Check-in im Admin, per REST (`POST /service-bookings/{id}/check-in`) oder automatisch, wenn ein verknüpftes
  Ticket eingecheckt wird (`wpcap_ticket_checked_in`).
- No-Show-Regel `mark`: Buchungen ohne Check-in werden nach Ende + Karenz vom Sweep als `no_show` markiert;
  `forfeit`: zusätzlich verfällt der Anspruch und die Gebühr `noshow_fee_minor` wird an der Buchung vermerkt
  und in der Mail genannt (der Einzug erfolgt außerhalb von Pass Captain). No-Shows zählen für die Sperre.

### Zugang für Kund:innen

- **Buchungsseite für Besucher:innen (A268)**: Shortcode `[wpcap_service_booking event="ID" resource="ID"]`
  bzw. Block `pass-captain/service-booking` (`src/Frontend/ServiceBooking.php`, `assets/service-booking.js`).
  Zeigt die buchbaren Ressourcen und freie Zeitfenster wochenweise, bucht über die öffentlichen Routen
  `GET /events/{id}/service-slots`, `POST /service-slots/{id}/bookings` und `POST /service-bookings/{id}/confirm`
  (bezahlte Ressourcen über den normalen Checkout) und verwaltet die Buchung danach über die
  Buchungs-Token-Routen (Umbuchen auf Ziele zum gleichen Preis, Stornieren). Kein Inline-JavaScript (strikte
  CSP), Skript mit Plugin-Version versioniert und nur dort geladen, wo ein Formular erscheint; Radiogruppen
  mit Beschriftungen, Live-Region für Ladezustand und Wochenwechsel. Einrichtung und Ablauf:
  `docs/OPERATIONS.md`, „Buchungsseite für Kund:innen (A268)“.
- **Bestellportal**: Buchungen erscheinen im Bestellportal (Filter `wpcap_order_portal_sections`) mit Formularen
  zum Umbuchen/Stornieren (POST an `admin-post.php`, Nonce, ohne JavaScript).
- **REST mit Bestellzugang** (Header `X-WPCAP-Access-Token`, alternativ Parameter `access_token`): `GET /orders/{public_id}/service-bookings`,
  `POST …/{booking_id}/cancel`, `POST …/{booking_id}/rebook` (`slot_id`, `idempotency_key`),
  `GET …/{booking_id}/rebook-options`.
- **Buchungen ohne Bestellung** (kostenlos/Pass): Buchungs-Token – `POST /service-bookings/{id}/cancel|rebook|confirm`
  oder Buchungsseite.
- Mail-Links zeigen per GET nur eine Bestätigungsseite; ausgeführt wird erst per POST.

## Warteliste für ausgebuchte Zeitfenster

- Eintragen: `POST /service-slots/{id}/waitlist` (`email`, `quantity`) – nur wenn das Zeitfenster voll ist.
  Antwort ist immer gleich (verrät nicht, ob die Adresse schon eingetragen ist); die Position kommt per Mail mit
  Link zur aktuellen Position und zum Austragen.
- **Double-Opt-in** (Einstellung `waitlist_double_opt_in`, Standard an; gilt auch für die Event-Warteliste): Die
  Anmeldung ist zunächst `unconfirmed` (ohne Position, keine Angebote) und bekommt die Mail
  `service_waitlist_confirm` mit `?wpcap_service_waitlist=ID&do=confirm&token=…` (48 Stunden gültig, gespeichert nur
  als Hash). Erst die Bestätigung (POST) vergibt die Position; sind schon Plätze frei, folgt sofort das Angebot.
  Abgelaufene unbestätigte Einträge löscht der Aufbewahrungslauf. Event-Warteliste: `?wpcap_waitlist_confirm=ID&token=…`,
  Mail `waitlist_confirm`.
- **Reihenfolge**: strikt nach Position. Ein Eintrag, der mehr Plätze braucht als frei sind, blockiert die
  nachfolgenden (nachvollziehbare Reihenfolge).
- **Angebot**: Wird ein Platz frei (Stornierung, Ablehnung, abgelaufener Hold, Kapazitätserhöhung), bietet der
  Job/Sweep ihn dem nächsten Eintrag an. Das Angebot **hält die Plätze** bis zum Ablauf
  (`waitlist_offer_minutes`, höchstens bis zum Buchungsschluss) – niemand sonst kann sie buchen.
- **Annahme** (`POST /service-waitlist/{id}/accept`, Seite `?wpcap_service_waitlist=ID&do=accept&token=…`):
  kostenlos → bestätigte Buchung; bezahlt → Bestellung mit Zahlungs-Hold, die Zahlung bestätigt die Buchung.
  Wiederholte/parallele Annahmen liefern dieselbe Buchung.
- **Ablehnen/Austragen/Ablauf** → der nächste Eintrag bekommt das Angebot automatisch.
- Position abfragen: `GET /service-waitlist/{id}` mit Header `X-WPCAP-Access-Token: <Token>` (`?token=…` bleibt aus
  Kompatibilitätsgründen erlaubt, landet aber in Zugriffslogs). Dasselbe gilt für alle Käufer-Routen mit
  `access_token`/`token` (siehe `docs/SECURITY.md`, „Zugangstokens nicht in URLs“).
- Admin: Warteliste je Zeitfenster mit Entfernen und „Freie Plätze jetzt anbieten“.

## Event-Warteliste mit Automatik

- Wird Kapazität einer Ticketart frei (`wpcap_capacity_released`: Stornierung, abgelaufener Hold, Erstattung aller
  Tickets, Widerruf; `wpcap_refund_completed`: Teil-Erstattung) oder erhöht (Sweep), stellt ein Job
  (`waitlist_capacity_freed`) dem nächsten wartenden Eintrag ein Angebot **mit Reservierung** zu.
- Das Angebot wird unter derselben Zeilensperre (Ticketart, Termin) erstellt wie Bestellungen; reservierte
  Tickets sind über die Filter `wpcap_ticket_availability` und `wpcap_reserve_capacity` für alle anderen
  gesperrt. Nur die Bestellung aus der Annahme dieses Angebots darf sie verwenden.
- Mail mit Annahme-, Ablehnungs- und Austragelink; Annahmefrist aus der Einstellung „Event-Warteliste:
  Annahmefrist eines Angebots (Stunden)“ (`waitlist_offer_hours`, Standard 24, 1–720; Filter
  `wpcap_waitlist_offer_hours`).
- Annahme legt die Bestellung an; in **derselben Transaktion** (Hook `wpcap_order_creating`) wird das Angebot auf
  „angenommen“ gesetzt und seine Reservierung aufgelöst – die Reservierung der Bestellung ersetzt sie, ohne dass
  ein Ticket kurzzeitig doppelt zählt. Parallele Annahmen verschiedener Angebote gelingen alle, parallele Annahmen
  desselben Angebots ergeben eine Bestellung, parallele Käufe anderer scheitern (getestet mit echten Prozessen).
  Dasselbe gilt für die Warteliste der Zeitfenster (die Buchung verbraucht das Angebot in ihrer Transaktion).
- Ablehnen (`POST /waitlist/{id}/decline`, Seite `?wpcap_waitlist_decline=…`), Austragen
  (`POST /waitlist/{id}/unsubscribe`, Seite `?wpcap_waitlist_leave=…`, Link schon in der Eintragungsmail),
  Ablauf (Sweep) → nächster Eintrag.
- Manuelles Angebot im Admin: reserviert, wenn ein Ticket frei ist; sonst Einladung ohne Reservierung.
- Kapazitätsprüfung bei begrenztem Termin: Angebote anderer Ticketarten desselben Termins werden konservativ
  abgezogen (es wird nie überverkauft, im Grenzfall eher zu früh „ausverkauft“ angezeigt).

## Service-Pässe (getrennt von Eventtickets)

Passregeln haben einen **Geltungsbereich**:

| `scope` | Verwendung |
|---|---|
| `event` (Standard) | Einlass zu Events (`Memberships::redeem()`, Scanner) – **nicht** für Ressourcen. |
| `service` | Nur Ressourcen (`Memberships::redeem_service()`) – **nicht** für den Eventeinlass. |
| `any` | Beides (nur wenn ausdrücklich so konfiguriert). |

Service-Regeln: `resource_id` (0 = alle Ressourcen), `slot_mode` `slot_free` (terminfrei: Nutzung direkt an der
Ressource) oder `slot_bound` (terminpflichtig: die Nutzung bucht einen Platz im Zeitfenster – Kapazität,
Kollision, Buchungsregeln; ohne Zahlung, Betrag 0), Einmal-/Mehrfachnutzung über `uses_total` (1 / >1 / 0 =
unbegrenzt), `uses_per_day`, Ablauf über die Passlaufzeit und optional `expires_at` je Regel. Eine voll belegte
Buchung verbraucht keine Nutzung; eine fristgerecht stornierte Pass-Buchung gibt die Nutzung zurück.
REST: `POST /member-passes/redeem-service` (`token`, `resource_id`, `slot_id`, `idempotency_key`).

**Wer welche Regel ausstellen darf** (`Memberships::validate_rule_for_current_user()`, gleich für die Admin-Seite
„Mitgliedspässe“ und `POST /member-passes`, dort für jede Regel): `event`-Regeln brauchen `wpcap_manage_orders` für
das Event; eine Ticketart muss zum Event gehören (das Event wird aus der Ticketart übernommen). `service`-Regeln
brauchen eine Ressource, die die Person verwaltet (`ServiceResources::can_manage()`); „alle Ressourcen“
(`resource_id` 0) und `any`-Regeln nur Administratoren. Ein `event_id` in einer Service-Regel wird verworfen.
Widerrufen/Ändern (`POST /member-passes/{id}/revoke`, Admin) erfordert dieselbe Berechtigung für **jede** Regel
des Passes.

## Mails (Vorlagen editierbar, deutsche Standardtexte)

`service_booking_confirmed`, `service_booking_pending`, `service_booking_partner_request`,
`service_booking_declined`, `service_booking_cancelled`, `service_booking_rebooked`, `service_booking_no_show`,
`service_waitlist_joined`, `service_waitlist_offer`; geändert: `waitlist_joined` (Austragelink),
`waitlist_offer` (Reservierungshinweis, Ablehnen-/Austragelink).

## Hintergrundaufgaben

- Sweep vor jedem Job-Lauf (`wpcap_process_jobs`, Priorität 5): Bestätigungsfristen, No-Shows, abgelaufene
  Angebote beider Wartelisten, frei gewordene Kapazität.
- Jobs: `service_slot_freed`, `service_booking_refund`, `waitlist_capacity_freed`.

## Audit-Log (Auszug)

`service_booking_reserved|confirmed|pending_confirmation|capacity_lost|cancelled|declined|rebooked|checked_in|no_show|refund_failed|partner_confirmed|order_attached|expired`,
`service_resource_partner_changed`, `service_waitlist_joined|offered|accepted|declined|left|removed|offer_expired`,
`waitlist_confirmed`, `waitlist_offer_made|offer_accepted|offer_declined|offer_expired|unsubscribed`, `member_pass_service_redeemed`,
`member_pass_use_released`.

## Tests

`tests/integration/modules/zzzz_services_a_rules.php` (Puffer/Kollisionen, Regeln, Veranstalter, Admin),
`_b_lifecycle.php` (Zahlung, Stornierung/Erstattung inkl. Gutschein, Umbuchung, Bestätigung, No-Show,
Kundenzugang; Fehlerinjektion Anbieter/Datenbank), `_c_waitlist.php` (beide Wartelisten),
`_d_passes.php` (Service-/Eventpässe), `_e_concurrency.php` (echte Parallelprozesse über
`tests/integration/workers/service_worker.php`: Kapazität, Kollisionen, Umbuchung, parallele Annahmen),
`_f_supplement.php` (Umbuchung mit Zuzahlung: Zuzahlungsbestellung, Zahlung, Ablauf, verspätete Zahlung,
Stornierung, parallele Zuzahlungen), `zz_a268.php` und `tests/js/service-booking.test.js` (Buchungsseite),
`zz_fe_17_widget.php` (Buchungsseite: strikte CSP ohne Inline-Handler, versionierte Assets, Beschriftungen und
Live-Region, Du-Form, REST-Routen mit Berechtigungsprüfung; No-Show-Gebühr ohne Einzug).

## Offene Punkte

- **Einzug der No-Show-Gebühr**: Die Gebühr (`noshow_fee_minor`, Regel `forfeit`) wird nur an der Buchung
  vermerkt und in der Mail genannt, nicht eingezogen. Ein Einzug bräuchte eine Entscheidung (Zahlungsmittel
  hinterlegen oder Zahlungslink, Einwilligung/AGB, Umgang mit Rechnung und Steuer, Mahnung bei Nichtzahlung)
  und ist deshalb nicht gebaut.
- **Verkauf von Zeitfenstern über WooCommerce**: nicht gebaut; bezahlte Zeitfenster laufen über den
  eigenen Checkout (`ServiceBookingFlow::checkout()`).
- Die Verwaltungsansicht der Buchungsseite (Buchungs-Token) bietet nur Ziele zum gleichen Preis an; eine
  Umbuchung auf ein teureres Zeitfenster mit Zuzahlung geht über das Bestellportal bzw. die REST-Routen mit
  Bestellzugang.
