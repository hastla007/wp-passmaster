# Gästelisten & Freikarten

Modul `src/Modules/GuestLists.php`, Code in `src/GuestLists/` (Service = Workflow, Page = Admin-Seite,
Rest = Namenssuche für das Einlasspersonal). Admin: **Pass Captain → Gästelisten**.

## Begriffe

- **Gästeliste** – gehört zu einem Event, optional zu genau einem Termin. Felder: Name (z. B. „Presse“,
  „Künstler“, „Sponsor“, „Personal“), Grund (`press`, `artist`, `sponsor`, `staff`, `promotion`,
  `other`), Kontingent (Tickets inkl. Begleitpersonen, 0 = unbegrenzt), verantwortliche Person
  (Owner = anlegende Person), optional eine **freigebende Person** (Benutzer-ID) und eine Notiz.
- **Eintrag** – ein Gast: Name, E-Mail (optional), Firma/Medium, Anzahl Tickets inkl. Begleitung,
  Ticketart, Notiz.

Statusfluss eines Eintrags:

```
requested ──approve──▶ approved ──issue──▶ issued ──arrive──▶ (Tickets eingecheckt)
    │                     │                   │
    └──reject──▶ rejected ◀┘                   └──cancel──▶ cancelled (Tickets gesperrt, Kapazität frei)
requested/approved ──cancel──▶ cancelled
```

`issuing` ist ein kurzer Zwischenstatus während der Ausstellung (Wiederholung ist sicher, siehe unten).

## Rechte

| Aktion | Voraussetzung |
|---|---|
| Listen anlegen/ändern, Gäste eintragen, CSV-Import, Ausstellen, Erneut senden, Stornieren, Export | `wpcap_manage_events` mit Event-Zuweisung (Administratoren: alle Events) |
| Freigeben / Ablehnen | zusätzlich `wpcap_approve_comps` und Event-Zuweisung (`wpcap_manage_events` oder `wpcap_approve_comps`); hat die Liste eine freigebende Person, nur diese (oder Administratoren) |
| Abholliste ansehen/drucken | Event-Zuweisung `wpcap_manage_events` oder `wpcap_checkin` |
| Namenssuche, „Angekommen“ | `wpcap_checkin` mit Event-Zuweisung (wie beim Scanner) |

Dasselbe Recht brauchen alle anderen Freikarten-Wege: Kulanz-Umtausch ohne Aufpreis (`waive`) und die
Kassen-Zahlart „Freikarte“ (auch beim Kassen-Umtausch); ohne das Recht wird die Zahlart an der Kasse
nicht angeboten und die Aktion mit 403 abgelehnt.

`wpcap_approve_comps` wird beim ersten Laden des Moduls (und nach jedem Schema-Update) der Rolle
*Administrator* und – falls vorhanden – der Rolle *Pass Captain Ticket-Administration*
(`wpcap_ticket_admin`) hinzugefügt. Andere Rollen bekommen das Recht nur manuell (z. B. per
Rollen-Plugin). Das Recht ist bewusst getrennt: Wer Gäste einträgt, soll sie nicht selbst freigeben
müssen (Vier-Augen-Prinzip).

## Kontingent

Freigegebene, in Ausstellung befindliche und ausgestellte Einträge zählen gegen das Kontingent der
Liste. Die Prüfung erfolgt bei der Freigabe unter einer Zeilensperre auf der Liste, gleichzeitige
Freigaben können das Kontingent also nicht überschreiten. Offene Anfragen dürfen das Kontingent
übersteigen (die freigebende Person wählt aus). Das Kontingent kann nicht unter die bereits
vergebenen Tickets gesenkt werden.

## Ausstellung

„Ausstellen“ legt pro Eintrag eine 0-€-Bestellung über `Orders::create()` an – nach demselben Muster wie
die Freikarte an der Kasse (`pos_free`):

- vertrauenswürdige interne Optionen: `external`, `payment_provider = comp`, `key_namespace = guestlist`,
  `source = guestlist`, `unit_prices`/`line_totals`/`line_taxes` = 0, `allow_door_sales`
  (Verkaufsfenster gelten nicht, ein beendeter Termin aber schon), `skip_rate_limit`;
- dieselben Kapazitätssperren wie im Online-Verkauf: Freikarten **verbrauchen die Kapazität** von
  Ticketart und Termin (und Modul-Kontingenten über `wpcap_reserve_capacity`). Ist ausverkauft, bleibt
  der Eintrag „freigegeben“ und kann später erneut ausgestellt werden;
- der Grund steht im `price_snapshot` der Bestellung unter `comp` (`reason`, `list_id`, `list`,
  `entry_id`, `approved_by`, `vat` = 0);
- `Orders::confirm_external_payment()` bestätigt die Bestellung, stellt die Tickets aus und mailt sie an
  den Gast. Das erste Ticket trägt den Namen des Gastes, weitere „Begleitung von …“;
- ohne E-Mail-Adresse bekommt die Bestellung die Platzhalteradresse
  `guestlist+<hash>@invalid.example`; Mails dorthin werden unterdrückt (`pre_wp_mail`). Die Tickets
  stehen dann auf der Abholliste. „Erneut senden“ mit neuer E-Mail-Adresse hinterlegt sie und mailt
  die Tickets (Codes werden dabei erneuert);
- die Idempotenz läuft über den Kanal-Schlüssel je Eintrag: ein wiederholtes „Ausstellen“ verwendet
  dieselbe Bestellung und ergänzt nur fehlende Schritte.

**Stornieren** eines ausgestellten Eintrags storniert die Bestellung (`Orders::cancel_order`): gültige
Tickets werden gesperrt, die Kapazität wird frei. Sobald ein Ticket des Eintrags eingecheckt wurde, ist
das Stornieren gesperrt. Wird die Bestellung an anderer Stelle storniert oder erstattet, wird der
Eintrag automatisch storniert.

## Berichte und Abrechnungen

Bestellungen mit einem Freikarten-Zahlungsanbieter (`comp`; erweiterbar über den Filter
`wpcap_comp_payment_providers`) sind **keine Verkäufe**:

- Umsatzbericht (`Reports\Data::build`): weder Tickets noch Umsatz/USt; separat als
  `comps` (`orders`, `tickets`) und in der Übersicht/CSV als „Freikarten (nicht im Umsatz)“. In der
  Auslastung je Termin ist `sold` ohne Freikarten, `comps` separat; die Auslastungsquote enthält beide
  (Freikarten belegen Plätze).
- Veranstalter-Abrechnungen (`Partners\Settlements`): Freikarten erzeugen keine Abrechnungszeilen,
  keine Provision und keine Ticketgebühr; informativ `totals.comp_tickets` (Bestellungen der Periode)
  und eine CSV-Zeile „Freikarten (nicht abgerechnet)“.

Die Freikarte der Kasse (`pos_free`) bleibt unverändert ein Kassenvorgang mit 0 €.

## Umsatzsteuer – Hinweis für Betreiber

Freikarten werden mit 0 € und 0 % USt ausgestellt; das Plugin erzeugt dafür keine Umsatzsteuer.
**Achtung:** Werden Freikarten als Gegenleistung abgegeben – etwa im Rahmen von Sponsoring,
Medienkooperationen oder im Tausch gegen Werbe- oder Dienstleistungen –, kann ein tauschähnlicher
Umsatz vorliegen, der umsatzsteuerpflichtig ist (Bemessungsgrundlage ist dann in der Regel der Wert
der Gegenleistung). Auch unentgeltliche Zuwendungen können im Einzelfall steuerlich relevant sein.
Die Beurteilung, Bewertung und Erfassung (z. B. in der Buchhaltung oder einem Fiskalsystem) ist
**Pflicht des Betreibers** und erfolgt außerhalb des Plugins; der Grund (`price_snapshot.comp.reason`)
und der CSV-Export helfen bei der Zuordnung. Die Admin-Seite zeigt diesen Hinweis an. Ist die
automatische Rechnungserstellung aktiv, entsteht – wie bei anderen 0-€-Bestellungen – ein Beleg über 0 €.

## Abholliste und Einlass nach Namen

- **Abholliste drucken** (Admin-Seite, Abschnitt „Abholliste & Einlass nach Namen“): alphabetisch
  (Umlaute wie ihr Grundbuchstabe), Firma, Liste, Personen inkl. „+n Begleitung“, Ticketart,
  Kurzcodes der Tickets (die ersten 8 Zeichen der Ticket-ID, groß geschrieben) und Check-in-Stand.
  Enthalten sind ausgestellte sowie freigegebene, noch nicht ausgestellte Einträge.
- **Namenssuche** (Admin-Seite oder REST, nur ausgestellte Einträge, keine E-Mail-Adressen in der
  Antwort): nach Name, Firma oder Kurzcode.
- **Angekommen** checkt die angegebene Anzahl (Standard: alle) noch gültiger Tickets des Eintrags über
  den normalen Check-in-Dienst (`Checkin::perform`, Quelle `guestlist`) ein – Event-Zuweisung,
  Einlassregeln, Check-in-Protokoll und Berichte gelten wie beim Scannen. Da nur Code-Hashes
  gespeichert sind, erhält jedes Ticket dafür einen neuen Code (wie beim Drucken); der gemailte QR-Code
  wird damit ungültig. Bei Tickets mit Mehrfacheinlass muss der Gast für einen Wiedereinlass erneut
  über die Namenssuche eingelassen werden.

REST (Cookie-Anmeldung + REST-Nonce, Recht `wpcap_checkin` oder `wpcap_manage_events`, Event-Zuweisung
wird je Termin/Eintrag geprüft):

```
GET  /wp-json/wp-pass-captain/v1/guestlists/search?occurrence_id=12&q=muster
POST /wp-json/wp-pass-captain/v1/guestlists/entries/{entry_id}/arrive   {"count": 2}
GET  /wp-json/wp-pass-captain/v1/guestlists/will-call?occurrence_id=12
```

## CSV

- **Import** (pro Liste, Textfeld oder Datei, max. 1000 Zeilen): `Name;E-Mail;Anzahl;Ticketart`,
  Kopfzeile optional, Komma als Trenner wird erkannt. Ticketart als ID oder Name; leer = die im
  Formular gewählte Standard-Ticketart. Importierte Gäste sind Anfragen; fehlerhafte Zeilen werden mit
  Zeilennummer gemeldet.
- **Export** (alle Listen eines Events oder eine Liste): Liste, Grund, Termin, Name, E-Mail, Firma,
  Tickets, Begleitpersonen, Ticketart, Status, Ticketcodes, Eingecheckt, Notiz. Zellen sind gegen
  Formel-Injection geschützt; Platzhalteradressen werden nicht exportiert.

## Datenmodell

- `wpcap_guest_lists` – list_id, event_id, occurrence_id (0 = alle Termine), name, reason, quota,
  owner_id, approver_id, note, status (`active`/`archived`), created_by, created_at, updated_at.
- `wpcap_guest_entries` – entry_id, list_id, event_id, occurrence_id, ticket_type_id, name, email,
  company, quantity, note, status, order_id (eindeutig), requested_by, decided_by, decided_at,
  issued_at, cancelled_at, arrived_at, created_at, updated_at.

Audit-Ereignisse: `guestlist_created`, `guestlist_updated`, `guestlist_archived`,
`guestlist_entry_requested`, `…_approved`, `…_rejected`, `…_issued`, `…_resent`, `…_cancelled`,
`…_arrived`.

Datenschutz: Gästedaten (Name, E-Mail, Firma) liegen in `wpcap_guest_entries` und – nach der
Ausstellung – wie bei jeder Bestellung an Bestellung und Tickets.
