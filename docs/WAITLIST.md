# Warteliste und automatische Angebote

Ausverkaufte Ticketarten haben eine Warteliste je Event, Termin und Ticketart. Wird Kontingent frei,
bietet das Plugin die Plätze automatisch den nächsten passenden Einträgen an – und **reserviert sie
dabei**, damit niemand anderes sie in der Zwischenzeit kaufen kann.

## Einträge

| Feld | Bedeutung |
|---|---|
| `quantity` | Gewünschte Anzahl (1 bis `max_per_order` der Ticketart). |
| `position` | Reihenfolge der Eintragung (FIFO) je Event und Ticketart. |
| `priority` | Optional, vom Team gesetzt (−1000 … 1000). Höhere Priorität kommt zuerst, innerhalb einer Priorität entscheidet die Position. |
| `status` | `waiting` → `offered` → `accepted` → `paid`; Endzustände `expired`, `declined`, `skipped`, `removed`. |
| `order_id` | Bestellung, die das offene Angebot reserviert (0 ohne Angebot). |
| `offer_expires_at` | Ende der Reservierung (UTC): beim Angebot die Annahmefrist, nach der Annahme das Zahlungsziel. |

Eintragen: `POST /wp-json/wp-pass-captain/v1/events/{event_id}/waitlist` mit `occurrence_id`,
`ticket_type_id`, `email` und optional `quantity`. Die Antwort ist immer gleich und verrät nicht, ob
die Adresse schon eingetragen war; die Position steht in der Bestätigungsmail (`waitlist_joined`).
Einträge mit Status `expired`, `declined` oder `paid` dürfen sich erneut eintragen (am Ende der
Schlange); vom Team übersprungene oder entfernte Einträge nicht. Wer sich selbst ausgetragen hat
(siehe unten), kann sich später neu eintragen – der alte Eintrag ist anonymisiert.

**Auf der Eventkarte** (Shortcode/Block): Ausverkaufte Ticketarten zeigen unter dem Bestellformular
ein Wartelisten-Formular (Ticketart, Anzahl, E-Mail), das diesen Endpunkt aufruft. Ist der Termin ganz
ausverkauft, entfällt das Bestellformular. Ausblenden: `add_filter( 'wpcap_waitlist_public_enabled',
'__return_false' )` (zweiter Parameter: Termin-Zeile der Karte).

## Ablauf

1. **Kontingent wird frei** – `wpcap_capacity_released` (Reservierung abgelaufen, Storno, Erstattung,
   Widerruf, verspätete Zahlung ohne Kontingent) oder `wpcap_capacity_increased` (von Modulen bzw. nach
   einer Kontingenterhöhung auszulösen). Alle Ticketarten desselben Termins mit wartenden Einträgen
   werden vorgemerkt (Option `wpcap_waitlist_queue`), denn das Termin-Kontingent ist gemeinsam.
2. **Verarbeitung mit kurzer Verzögerung** – ein einmaliges Cron-Ereignis `wpcap_waitlist_process`
   (Standard 60 Sekunden, Filter `wpcap_waitlist_process_delay`) bündelt mehrere Freigaben. Zusätzlich
   läuft die Verarbeitung im 5-Minuten-Job (`wpcap_process_jobs`) und stündlich als Sweep über alle
   Ticketarten mit wartenden Einträgen (`wpcap_cleanup_reservations`), der auch Kontingenterhöhungen
   ohne Hook erfasst.
3. **Angebot** – je Ticketart (mit Datenbanksperre, keine parallelen Läufe) wird die freie Menge aus
   `Orders::availability()` ermittelt. Einträge werden nach Priorität und Position durchgegangen; ein
   Eintrag erhält ein Angebot, wenn seine Anzahl in die freie Menge passt. Größere Einträge werden
   übersprungen, kleinere dahinter bedient – außer mit der Einstellung *Strikte Reihenfolge*. Höchstens
   *Angebote pro Durchlauf* je Lauf und Ticketart.
4. **Reservierung** – für das Angebot wird eine ausstehende Platzhalter-Bestellung angelegt
   (`Orders::create()` mit `payment_provider` `waitlist_offer`, Kanal `waitlist`) mit einer Reservierung
   über die angebotene Anzahl bis zum Ablauf der Annahmefrist (Einstellung, Standard 24 Stunden,
   höchstens bis Terminbeginn). Das Kern-Kontingent zählt diese Reservierung; öffentliche Käufer:innen
   können die Plätze nicht kaufen. Ein Eintrag hat höchstens ein offenes Angebot.
5. **Mail** `waitlist_offer` mit Anzahl, Frist und Link zur Angebotsseite
   (`/?wpcap_waitlist_accept={id}&token=…`). Schlägt der Versand fehl, wird das Angebot zurückgenommen
   und der Eintrag wartet weiter.
6. **Annahme** – die Angebotsseite zeigt Event, Ticketart, Anzahl, Preis und Frist und bietet
   *Annehmen* (mit Zahlungsart-Auswahl, falls mehrere aktiv sind, und ggf. Bestätigung der Bedingungen)
   und *Ablehnen*.
   - Kostenlose Tickets: die Bestellung wird sofort abgeschlossen, die Tickets werden versendet
     (`paid`).
   - Kostenpflichtige Tickets: die Bestellung erhält die gewählte Zahlungsart, die Reservierung wird
     auf das Zahlungsfenster dieser Zahlungsart verlängert (nie verkürzt), die Zahlung wird gestartet
     (Weiterleitung bzw. Bankdaten) und der Bestell-Link per Mail versendet (`accepted`). Mit der
     Zahlungsbestätigung (`wpcap_order_paid`) wird der Eintrag `paid`.
7. **Ablehnung** – der Eintrag wird `declined`, die Platzhalter-Bestellung storniert; die Plätze gehen
   an den nächsten Eintrag.
8. **Ablauf** – läuft die Reservierung eines offenen oder angenommenen, unbezahlten Angebots ab, setzt
   der Kern-Cleanup die Bestellung auf `expired`; der Eintrag wird `expired` und die Plätze werden
   erneut angeboten. Die Warteliste prüft abgelaufene Angebote zusätzlich selbst (5-Minuten-Job und
   eigenes Cron-Ereignis kurz nach der Frist), damit das nächste Angebot nicht bis zum stündlichen
   Cleanup wartet.

Alle Übergänge sind bedingte Updates (Status + Token), Mehrfachaufrufe von Hooks und Läufen ändern
nichts doppelt.

## Selbst austragen

Jede Wartelisten-Mail (`waitlist_joined`, `waitlist_offer`) enthält einen persönlichen Link zum
Austragen (Platzhalter `{unsubscribe_link}`, `/?wpcap_waitlist_unsubscribe={id}&token=…`). Fehlt der
Platzhalter in einer angepassten Vorlage, hängt das Plugin den Standardsatz mit dem Link an.

- **Token:** Schlüssel-Hash aus Eintrag und E-Mail-Hash (Site-Secret `wp_salt('auth')`), damit alle
  Mails eines Eintrags denselben Link tragen. Gespeichert wird nur dessen SHA-256 in
  `unsubscribe_token_hash` (Schema 3.10.1); Vergleich in konstanter Zeit. Einträge von vor 3.10.1
  bekommen den Hash mit der nächsten Mail. Aufbewahrungsfrist und Löschanfrage leeren den Hash – der
  Link ist dann ungültig.
- **Kein Austragen per GET:** Der Link öffnet nur eine Bestätigungsseite (Event, Ticketart, Anzahl,
  Status; bei offenem Angebot der Hinweis, dass die Tickets sofort freigegeben werden). Erst das
  Formular (POST an `admin-post.php`, Aktion `wpcap_waitlist_unsubscribe`, Nonce) trägt aus. So lösen
  Mail-Scanner und Link-Vorschauen nichts aus.
- **Wirkung:** Status `removed`, E-Mail-Adresse geleert, E-Mail-Hash ersetzt (wie bei der
  Aufbewahrungsfrist), Angebots-Token entfernt. Ein offenes Angebot wird zurückgezogen: die
  Platzhalter-Bestellung wird storniert, die Reservierung freigegeben und die Ticketart erneut
  vorgemerkt, damit der nächste Eintrag das Angebot bekommt. Audit `waitlist_unsubscribed`
  (`previous`, `order_id`, `source`), Action `wpcap_waitlist_unsubscribed`.
- **Idempotent:** Ein erneuter Aufruf meldet „nicht mehr auf der Warteliste“ ohne Änderung (auch für
  bezahlte, abgelaufene oder abgelehnte Einträge). Ein bereits **angenommenes** Angebot (Zahlung
  offen) lässt sich nicht austragen (409) – zahlt die Person nicht, verfällt die Reservierung wie
  gewohnt.
- **Rate-Limit:** höchstens 30 Versuche je Client-Adresse und 10 je Eintrag und Stunde (429), für
  Seite und Formular.
- **Bestellportal:** Hat die Käufer-Adresse einer Bestellung offene Einträge (wartend oder Angebot
  offen), zeigt das Portal die Karte *Deine Wartelisten* mit *Von der Warteliste austragen* (POST an
  den Portal-Link mit Nonce, Rückmeldung per Redirect). Austragen geht dort nur für Einträge mit
  derselben E-Mail-Adresse.

## Gleichzeitige Aktionen

Annehmen, Ablehnen, Austragen, Team-Aktionen und der Ablauf-Job können dasselbe Angebot gleichzeitig
treffen. Garantien:

- **Annahme in einer Transaktion:** Platzhalter-Bestellung, Eintrag und die aktiven Reservierungen
  dieser Bestellung werden gesperrt (`FOR UPDATE`, Bestellung zuerst – dieselbe Reihenfolge wie in
  `Orders`). Unter den Sperren werden Status `offered`, Token, Frist, `payment_provider`
  `waitlist_offer` und eine noch laufende Reservierung erneut geprüft. Erst dann wird der Eintrag
  `accepted`, die Reservierung verlängert (kostenlos: mindestens 15 Minuten für den Abschluss;
  kostenpflichtig: Zahlungsfenster der Zahlungsart) und die Zahlungsart gesetzt. Zwei parallele
  Annahmen: genau eine gewinnt, die zweite bekommt 409.
- **Annahme gegen Ablauf:** Ist die Reservierung zeitlich abgelaufen, aber der Ablauf-Job noch nicht
  gelaufen, wird die Annahme abgelehnt. Gewinnt die Annahme, ist die Reservierung bereits verlängert,
  bevor der Ablauf-Job sie sieht; er lässt das Angebot dann stehen. Verliert die Annahme einen
  Deadlock gegen den Ablauf-Job, wird alles zurückgerollt und einmal neu versucht.
- **Ablehnen, Austragen, Überspringen/Entfernen** sind bedingte Updates bzw. sperren Bestellung und
  Eintrag ebenfalls; nach einer Annahme schlagen sie fehl (bzw. melden 409), vor ihr verhindern sie
  die Annahme. Die Stornierung der Platzhalter-Bestellung wird bei einem Sperrkonflikt sofort einmal
  wiederholt, danach übernimmt der Wiederholungs-Job von `Orders::cancel_order()`.
- **Eintrag während des Angebots entfernt:** Wird ein Eintrag zwischen Beanspruchung und Verknüpfung
  der Platzhalter-Bestellung entfernt (Austragen, Team, Löschanfrage), storniert das Angebot seine
  eben angelegte Bestellung sofort wieder – keine verwaiste Reservierung.

Tests: `tests/integration/modules/zz_a158.php` (deterministische Reihenfolgen) und
`php tests/load/run.php --scenario=waitlist` (echte parallele Verbindungen: parallele Annahmen,
Annahme gegen Ablehnung/Austragen, Annahmen rund um den Ablauf mit Ablauf-Job in Schleife; Teil von
`--profile=ci`).

## Admin

*Pass Captain → Warteliste* (Recht `wpcap_manage_orders`, Event-Berechtigung wie bei Bestellungen):

- Einträge je Event mit Rang in der Schlange, Position, Ticketart, E-Mail, Anzahl, Priorität, Status,
  Reservierungsende und Bestellung.
- Selbst ausgetragene Einträge erscheinen als *Entfernt* ohne E-Mail-Adresse.
- Aktionen: *Jetzt anbieten* (auch bei abgeschalteten automatischen Angeboten), *Priorität setzen*,
  *Überspringen* (`skipped`) und *Entfernen* (`removed`); ein offenes, unbezahltes Angebot wird dabei
  zurückgezogen und die Reservierung freigegeben.
- *Freie Plätze jetzt anbieten* verarbeitet alle Ticketarten des Events sofort.
- Einstellungen (nur Administrator:innen): automatische Angebote an/aus, Gültigkeit eines Angebots in
  Stunden (1–336), höchstens Angebote pro Durchlauf (1–100), strikte Reihenfolge.

## REST

| Route | Recht | Zweck |
|---|---|---|
| `POST /events/{id}/waitlist` | öffentlich | Eintragen (`occurrence_id`, `ticket_type_id`, `email`, `quantity`). |
| `GET /events/{id}/waitlist/entries` | Event verwalten | Einträge mit Rang und Status. |
| `POST /events/{id}/waitlist/offer` | Event verwalten | Nächsten Eintrag einer Ticketart (`ticket_type_id`) sofort anbieten. |
| `POST /waitlist/{id}/accept` | Token | Angebot annehmen (`token`, optional `provider`, `accept_terms`). |
| `POST /waitlist/{id}/decline` | Token | Angebot ablehnen (`token`). |

Das Austragen läuft bewusst nicht über REST, sondern über die Bestätigungsseite (POST + Nonce) bzw.
das Bestellportal.

## Hinweise

- Platzhalter-Bestellungen erscheinen in der Bestellliste als ausstehend mit Zahlungsart
  `waitlist_offer`; sie werden nicht bezahlt, sondern bei der Annahme auf die gewählte Zahlungsart
  umgestellt.
- Rabattcodes, Gutscheine und Rechnungsdaten werden bei Wartelisten-Bestellungen nicht abgefragt.
