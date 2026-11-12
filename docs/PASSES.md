# Pässe (Festival-/Mehrtagespässe)

Modul `PassCaptain\Modules\Passes` (`src/Modules/Passes.php`, Dienste in `src/Passes/`).
Admin: **Pass Captain → Pässe** (Capability `wpcap_manage_events`, eventbezogen über `Access`;
für jedes Event der gewählten Termine nötig).

## Modell

Ein Pass ist eine **normale Ticketart** (`wpcap_ticket_types`), deren `occurrence_id` der
**Verkaufstermin** („Anker“) ist, plus:

| Tabelle | Inhalt |
|---|---|
| `wpcap_pass_types` | `type_id`, `entry_mode` (`once`/`reentry`), `max_occurrences` (N aus M, 0 = alle), `area_ids` (JSON), `refund_per_day_minor`, `on_cancel` (`keep`/`revoke`) |
| `wpcap_pass_occurrences` | `(type_id, occurrence_id)` – die enthaltenen Termine (gleiches Event oder mehrere Events eines Festivals/einer Serie) |
| `wpcap_pass_gates` | eine Zeile je enthaltenem Termin: Sperrzeile für Bestellungen und optionales `pass_quota` |

Der Anker kann ein enthaltener Termin sein (z. B. erster Festivaltag) oder ein eigener
Verkaufstermin („Festivalpass“, Kapazität 0), der selbst nicht zum Einlass berechtigt. Online endet
der Verkauf wie bei jeder Ticketart mit dem Beginn des Ankers (Abendkasse: bis zu dessen Ende).

API:

```php
use PassCaptain\Passes\Definitions;
$type_id = Definitions::create( array( 'occurrence_id' => $anchor, 'name' => 'Festivalpass', 'price_minor' => 9900,
	'occurrence_ids' => array( $tag1, $tag2, $tag3 ), 'entry_mode' => 'once', 'max_occurrences' => 0,
	'area_ids' => array(), 'refund_per_day_minor' => 2000, 'on_cancel' => 'keep' ) );
Definitions::save( $existing_type_id, array( 'occurrence_ids' => … ) ); // bestehende Ticketart zum Pass machen/ändern
Definitions::set_quota( $occurrence_id, 200 ); // höchstens 200 Pässe (aller Pass-Arten) an diesem Termin
Definitions::remove( $type_id );                // nur solange kein Pass verkauft/reserviert ist
```

Beim Ändern eines verkauften Passes werden neu hinzugefügte Termine geprüft (genug freie Plätze
für die bereits verkauften Pässe), sonst Fehler `wpcap_pass_capacity`.

## Kapazität

**Jeder verkaufte Pass belegt in JEDEM enthaltenen Termin einen Platz** – auch bei „N aus M“
(Entscheidung: Reservierung in allen M Terminen; die Gäste wählen die N Termine erst beim Einlass,
damit kann kein gewählter Tag überbucht sein. Nachteil: bei N < M bleiben Plätze ungenutzt; dafür
gibt es das Pass-Kontingent je Termin).

```
belegt(Termin) = Kern-belegt(Termin)                             (Ticketarten mit occurrence_id = Termin, inkl. dort verankerter Pässe)
               + Pass-Einheiten verknüpfter Pässe mit anderem Anker (bezahlt − erstattet + aktive Reservierungen)
frei(Ticketart) = min( Kern-Wert, Kapazität(Termin) − belegt(Termin) für jeden betroffenen Termin,
                       Pass-Kontingent − alle Pass-Einheiten am Termin  (nur für Pässe) )
```

Die Nutzung wird – wie im Kern – immer abgeleitet (Positionen und Reservierungen), nie gezählt.
Ablauf, Storno, Erstattung, Widerruf geben Plätze damit automatisch in allen Terminen frei;
`wpcap_capacity_released` leert nur den Request-Cache. Abgesagte enthaltene Termine zählen nicht mehr.

Hooks:

- `wpcap_reserve_capacity` **Priorität 5** (`Capacity::reserve_lock`): nur sperrende Lesezugriffe;
  sperrt die Gate-Zeilen aller betroffenen Termine (Anker der Positionen + verknüpfte Termine, sofern
  ein Pass auf sie verweist) `FOR UPDATE` in **aufsteigender Termin-ID**.
- `wpcap_reserve_capacity` **Priorität 1000** (`Capacity::reserve_check`): liest die Nutzung und prüft
  Kapazität und Pass-Kontingent. Fehlercodes: `occurrence_sold_out` (Einzelticket, Kernmeldung),
  `pass_occurrence_sold_out` („Der Pass ist nicht mehr verfügbar: Der enthaltene Termin „X“ am
  TT.MM.JJJJ ist ausgebucht.“), `pass_quota_exhausted`, `db_busy`.
- `wpcap_ticket_availability` (Priorität 15): senkt die Verfügbarkeit von Pässen und von Ticketarten
  an Terminen, die in einem Pass enthalten sind.

### Sperrreihenfolge (deadlock-frei)

1. Kern: Ticketarten (aufsteigend), dann deren Termine (aufsteigend) – `FOR UPDATE`.
2. Pässe (Priorität 5): Gate-Zeilen `wpcap_pass_gates` (aufsteigend). Jede Bestellung, die einen
   enthaltenen Termin berührt – Pass oder Tagesticket –, serialisiert auf dessen Gate.
3. Andere Module (Serienkontingente 10, Kontingente 20) danach; Prüfung der Pässe zuletzt (1000),
   sodass der konsistente Snapshot erst nach allen Sperren entsteht.

Warum Gates statt der zusätzlichen Termin-Zeilen? Der Kern hält beim Pass bereits die Sperre des
Ankers. Liegt dessen ID über einem anderen enthaltenen Termin, würden zwei Pässe mit
unterschiedlichem Anker über dieselben Termine Termin-Zeilen in entgegengesetzter Reihenfolge
sperren (A: Anker 3 → 1, B: Anker 1 → 3) und sich gegenseitig blockieren. Gates werden in jeder
Transaktion erst nach allen Kernsperren und immer aufsteigend genommen; ein Zyklus ist damit
ausgeschlossen. Gates und Verknüpfungen werden vom Admin mit einzelnen Autocommit-Statements
geschrieben (Gate vor Verknüpfung), halten also nie Sperren, während sie auf Bestellungen warten.

## Einlass

Erweiterungspunkte im Kern (klein, mit `[Passes]` markiert):

| Filter | Ort | Zweck |
|---|---|---|
| `wpcap_ticket_valid_for_occurrence( $found, $context, $at )` | `Checkin::lookup()` | Ticket an den gescannten Termin binden (Termin, Event, Zeiten, Status) |
| `wpcap_admission_policy( $policy, $type_id )` | `Policies::for_type()` | eigene Einlassregel `pass` (wiederholbar; Ticketstatus bleibt `valid`) |
| `wpcap_admission_evaluate( null, $policy, $found, $state, $at )` | `Engine::evaluate_in()` | Entscheidung für eigene Regeln |
| `wpcap_ticket_allowed_areas( $ids, $found )` | `Areas::check()` | erlaubte Bereiche je gescanntem Event |

`Checkin::lookup()` hat dafür den optionalen Parameter `$context`. `Engine::record()` aktualisiert
`occurrence_id`/`event_id` des Einlasszustands, damit die Anwesenheit den Pass am zuletzt
gescannten Termin zählt.

Bindung eines Scans: Termin aus dem Scanner-Kontext (`occurrence_id`), sonst unter den enthaltenen
Terminen der Geräte-Events/des Bereichs derjenige, der am Betriebstag (inkl. Tagesgrenze
`admission_day_cutoff_hour`) gilt, sonst der zeitlich nächste. Passt kein enthaltener Termin,
wird der zeitlich nächste gewählt und der Scan mit `wrong_occurrence`/`wrong_event`/`not_valid_today`
abgewiesen. Danach gelten Eventzugriff, Gerätebindung, Bereiche und Einlassfenster (die Fenster-Felder
einer eigenen Einlassregel der Ticketart bleiben wirksam) wie bei normalen Tickets.

Regel `pass` (je Ticket **und** Termin, auf `wpcap_admission_entries`, die `occurrence_id` tragen –
keine eigene Zustandstabelle nötig):

- Betriebstag muss ein Tag des Termins sein (`wpcap_occurrence_days` oder Beginn–Ende), sonst `not_valid_today`;
- `once`: ein Einlass je Termin, danach `daily_limit_reached`;
- `reentry`: Wiedereinlass nach Auslass am selben Termin, solange drinnen `already_inside`;
- N aus M: Einlass an höchstens N verschiedenen Terminen, danach `entry_limit_reached`.

Bereiche: Pass-Bereiche des gescannten Events; ohne solche gelten die Bereiche der Ticketart nur
innerhalb dieses Events, sonst alle Eingänge. Check-out nutzt den ticketweiten Zustand (letzte Bewegung).

### Offline-Scanner

`Package::refresh()`/`build()` erhalten die Filter `wpcap_offline_live_tickets` und
`wpcap_offline_package_types`. Pass-Tickets stehen im Paket **jedes enthaltenen Termins** (nicht im
Paket eines Verkaufstermins, der nicht enthalten ist). Status im Paket: `checked_in` nach Einlass an
diesem Termin (Modus `once`) oder wenn N aus M ausgeschöpft ist, sonst `valid`. Ticketart-Eintrag:
`policy: "pass"`, `multi` = Wiedereinlass, `pass: true`. Konflikte zwischen Geräten verschiedener Tage
(N aus M) erkennt der Server bei der Synchronisierung (`entry_limit_reached` → Konflikt).

## Anzeige

- Ticket-Label (PDF im Ticketmail, Ticketseite, Bestellportal, Wallet, Druck) über
  `wpcap_ticket_seat_label`: „Gültig für: 01.08.2027, 02.08.2027 (3 aus 5 Terminen frei wählbar)“,
  bei mehreren Events mit Eventnamen, abgesagte Termine mit „(abgesagt)“. Labels anderer Module
  (Sitzplatz) bleiben vorne erhalten.
- Ticketmail (`wpcap_ticket_mail`) und Mails mit neuem Code (`wpcap_ticket_code_mail_section`).
- Eventkarte (`wp_pass_captain_event_card_html`): Termine neben dem Pass; auf Karten enthaltener
  Termine der Hinweis „Auch enthalten im Pass „X“ (erhältlich bei … am …)“.
- Shortcode `[wpcap_passes]`: Liste der Pässe im Verkauf mit Preis, Terminen und Verkaufstermin.

## Absage eines Termins

`wpcap_occurrence_cancelled`:

- Priorität 5: Wird der **Anker** abgesagt (`on_cancel = keep`), wandert der Pass auf den frühesten
  verbleibenden enthaltenen Termin. Der Kern (Priorität 10) widerruft/erstattet dann keine
  Pass-Tickets. Ohne verbleibenden Termin behandelt der Kern den Pass wie jedes Ticket.
- Priorität 20, `keep` (Standard): Pass bleibt für die übrigen Termine gültig. Ist eine
  **Teilerstattung pro Tag** gesetzt, erhält jede bezahlte Bestellung `refund_per_day_minor × Pässe`
  als Betragserstattung (`Refunds::request_amount`, `system`, idempotent je Termin und Pass; Freigabe-
  Schwelle gilt). WooCommerce-Bestellungen: Audit `pass_day_refund_pending` und Aktion
  `wpcap_pass_day_refund_failed( $order_id, $type_id, $occurrence_id, $amount_minor )` – in WooCommerce
  erstatten. Käufer erhalten die Absage-Mail mit Hinweis auf die weitere Gültigkeit.
- `revoke`: ganzer Pass wird widerrufen; mit `auto_refund_cancelled` werden bezahlte native
  Bestellungen für die Pass-Tickets erstattet.

Vollständige Erstattung eines Passes (`Refunds::request`) gibt die Plätze in allen Terminen frei.
Bei N aus M wird bei Absagen nicht nachgerechnet, ob noch N Termine übrig sind.

## Admin

**Pässe**: Übersicht (Verkaufstermin, Termine, Einlassmodus, verkauft, verfügbar), „Neuer Pass“
(Name, Verkaufstermin, Preis, USt., Anzahl, max. pro Bestellung, Termine, Einlass je Termin, N aus M,
Einlassbereiche, Absage-Verhalten, Teilerstattung pro Tag). Detailseite: freie Plätze und
Pass-Kontingent je Termin, Einlässe je Tag (Pässe / Einlässe), Einstellungen, Umwandeln in eine
normale Ticketart (nur unverkauft). Admin-Post-Aktionen: `wpcap_pass_create`, `wpcap_pass_save`,
`wpcap_pass_remove`, `wpcap_pass_quota`. Auswertung: `Passes\Admission::usage( $type_id )`.

## Tests

`tests/integration/modules/p3_passes.php` (Kapazität in allen Terminen, ausgebuchter Tag blockiert
Pass, Pass-Kontingent, parallele Käufe Pass/Tagesticket/Pass mit anderem Anker um den letzten Platz
ohne Deadlock, Einlass je Tag und Event, Wiedereinlass, N aus M, Offline-Pakete, Anzeige, Absage
eines Tages inkl. Anker und Teilerstattung, Widerruf, Vollerstattung).
