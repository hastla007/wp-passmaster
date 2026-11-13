# Betreiberpflichten: Vorlagen und Leitfäden

> **Keine Rechtsberatung – Vorlagen zur Prüfung durch Datenschutz-/Rechtsberatung.**
> Die folgenden Texte, Checklisten und Tabellen sind Arbeitsvorlagen. Sie beschreiben, was Pass Captain
> technisch tatsächlich speichert und weitergibt (Stand des Codes zum Zeitpunkt dieses Dokuments), damit
> der Betreiber seine eigenen Pflichten erfüllen kann. Ob eine Rechtsgrundlage trägt, welche Rolle ein
> Vertragspartner hat, welche Fristen gelten und ob eine Betriebsvereinbarung nötig ist, entscheidet der
> Betreiber mit seiner Datenschutz-, Rechts- bzw. Steuerberatung. Platzhalter stehen in `[eckigen Klammern]`.

Das Plugin kann diese Pflichten nicht selbst erfüllen. Es liefert die Bausteine (automatische Löschfristen,
Export/Löschung über die WordPress-Datenschutzwerkzeuge, Vorschlag für die Datenschutzerklärung unter
**Einstellungen → Datenschutz → Leitfaden**, Audit-Log); die organisatorischen Maßnahmen liegen beim
Betreiber. Allgemeine Fristen und Werkzeuge: [OPERATIONS.md – Datenschutz](OPERATIONS.md#datenschutz-löschfristen-auskunft-und-löschung).

Inhalt:

1. [Gästelisten: Information nach Art. 14 DSGVO und Vernichtung der Abholliste](#1-gästelisten-information-nach-art-14-dsgvo-und-vernichtung-der-abholliste) (A108)
2. [Rolle der Veranstalter und Wiederverkäufer](#2-rolle-der-veranstalter-und-wiederverkäufer) (A109)
3. [Kassen-Schichtdaten: Mitbestimmung und Aufbewahrung](#3-kassen-schichtdaten-mitbestimmung-und-aufbewahrung) (A110)
4. [Fiskalanbieter, Auskunft ohne E-Mail-Adresse, Verarbeitungsverzeichnis](#4-fiskalanbieter-auskunft-ohne-e-mail-adresse-verarbeitungsverzeichnis) (A111)
5. [Einwilligungen in Teilnehmerfeldern: Nachweis, Widerruf, Löschung](#5-einwilligungen-in-teilnehmerfeldern-nachweis-widerruf-löschung) (A116)

---

## 1. Gästelisten: Information nach Art. 14 DSGVO und Vernichtung der Abholliste

Gäste werden in der Regel nicht von sich aus erfasst, sondern von einer anderen Person eingetragen
(Pressestelle, Künstlermanagement, Sponsor, eigenes Team, CSV-Import). Dann gilt **Art. 14 DSGVO**
(Erhebung nicht bei der betroffenen Person). Trägt sich ein Gast selbst ein (z. B. über ein eigenes
Formular, dessen Daten importiert werden), gilt stattdessen Art. 13 DSGVO; der Text unten passt dann
sinngemäß.

### 1.1 Was das Plugin speichert

Grundlage: `src/GuestLists/Service.php`, `src/Modules/GuestLists.php`, [GUEST-LISTS.md](GUEST-LISTS.md).

| Ort | Daten | Löschung wie implementiert |
|---|---|---|
| Gästeliste (`wpcap_guest_lists`) | Name der Liste, Grund (`press`, `artist`, `sponsor`, `staff`, `promotion`, `other`), Kontingent, verantwortliche und freigebende Person (Benutzer-ID), Notiz | bleibt (keine Gästedaten) |
| Eintrag (`wpcap_guest_entries`) | **Name**, **E-Mail** (optional), **Firma/Medium**, Anzahl inkl. Begleitung, Ticketart, **Notiz**, Status, beantragt/entschieden von (Benutzer-IDs), Zeitpunkte (angelegt, entschieden, ausgestellt, storniert, angekommen) | Name, E-Mail, Firma und Notiz werden vom täglichen Löschlauf geleert, sobald das Terminende länger zurückliegt als die Frist für Teilnehmerangaben (Einstellung „Teilnehmerdaten nach dem Event löschen nach“, bei 0 Standard 365 Tage). Name wird zu „Gast (gelöscht)“; Anzahl, Status und Bestellbezug bleiben für Berichte. |
| 0-€-Bestellung je Eintrag (`wpcap_orders`, Quelle `guestlist`, Zahlungsanbieter `comp`) | Käufer-E-Mail = E-Mail des Gastes, ohne E-Mail die Platzhalteradresse `guestlist+<hash>@invalid.example`; im Preis-Schnappschuss der Grund (`comp.reason`, Liste, Eintrag, freigebende Person) | Seit A108b ersetzt der Löschlauf nach derselben Frist die Gast-E-Mail an der Freikartenbestellung durch die Platzhalteradresse und leert Teilnehmernamen/-E-Mails der Tickets (Bestellung und Zählwerte bleiben für Berichte). |
| Tickets der Bestellung | erstes Ticket mit dem Namen des Gastes, weitere „Begleitung“; Ticketcodes nur als Hash | Teilnehmername/-E-Mail mit der Teilnehmerfrist (siehe oben); Ticket und Einlassprotokoll bleiben ohne Namen |
| Einlassprotokoll | Zeitpunkt, Ergebnis, Quelle `guestlist`, Mitarbeitende und Gerät | Mitarbeitende/Gerät nach 365 Tagen entfernt |
| Audit-Log | Ereignisse `guestlist_entry_*` mit Listen-/Eintrags-ID, Anzahl, Grund – **keine Namen oder E-Mail-Adressen** | nach 730 Tagen gelöscht |
| E-Mail | Ticketmail (Mail-Typ `tickets`) an die angegebene Adresse; an Platzhalteradressen wird nichts versendet | Inhalt der Warteschlange nach 30 Tagen geleert |

Wer die Daten sieht: Personen mit `wpcap_manage_events` und Event-Zuweisung (Listen, Export), mit
`wpcap_checkin` und Event-Zuweisung (Abholliste, Namenssuche, „Angekommen“ – die Namenssuche gibt keine
E-Mail-Adressen aus), Freigebende mit `wpcap_approve_comps`. Der CSV-Export enthält Name, E-Mail, Firma
und Notiz; Platzhalteradressen werden nicht exportiert.

Auskunft und Löschung: Die WordPress-Werkzeuge finden Gästelisten-Einträge über die E-Mail-Adresse
(Exporter/Eraser „Pass Captain Gästelisten“). Einträge **ohne** E-Mail-Adresse findet das Werkzeug nicht –
dafür gilt der manuelle Ablauf in [Abschnitt 4.3](#43-manueller-auskunftsablauf-für-bestellungen-ohne-e-mail-adresse).

### 1.2 Vorlage: Information für Gäste (Art. 14 DSGVO)

> **Datenschutzinformation für Gäste von [Veranstaltung]**
>
> **Verantwortlicher:** [Name/Firma, Anschrift, E-Mail]. [Falls bestellt: Datenschutzbeauftragte:r: Kontakt].
>
> **Woher stammen Ihre Daten?** Sie wurden von [anmeldende Stelle, z. B. „Ihrer Redaktion“, „dem
> Management der Künstlerin“, „unserem Sponsor X“, „unserem Team“] für unsere Gästeliste angemeldet.
>
> **Welche Daten?** Ihr Name, gegebenenfalls Ihre E-Mail-Adresse und Ihre Firma bzw. Ihr Medium, die Zahl
> Ihrer Begleitpersonen, eine interne Notiz [z. B. „Fotopass“], der Anlass der Einladung (z. B. Presse,
> Künstler, Sponsor) sowie Zeitpunkt der Ausstellung und Ihres Einlasses.
>
> **Zweck und Rechtsgrundlage:** Wir verwenden die Daten, um Ihre Freikarte auszustellen, Ihnen die Tickets
> zuzusenden bzw. an der Abholung bereitzulegen und Ihren Einlass zu prüfen.
> [Rechtsgrundlage wählen und mit Beratung prüfen:]
> – [Art. 6 Abs. 1 lit. b DSGVO, soweit die Einladung ein Vertragsverhältnis mit Ihnen begründet bzw. dessen Durchführung dient;]
> – [Art. 6 Abs. 1 lit. f DSGVO; unser berechtigtes Interesse liegt in der Organisation der Gästeliste, der Vermeidung von Missbrauch der Freikarten und einer geordneten Einlasskontrolle;]
> – [bei Medienvertreter:innen ggf. zusätzlich: Durchführung einer Medienkooperation mit Ihrem Arbeitgeber, Art. 6 Abs. 1 lit. f DSGVO.]
> Den Anlass der Freikarte halten wir außerdem für unsere Buchhaltung fest, weil Freikarten steuerlich
> relevant sein können (Art. 6 Abs. 1 lit. c DSGVO).
>
> **Empfänger:** unser Hosting- und E-Mail-Dienstleister als Auftragsverarbeiter; das Einlasspersonal am
> Veranstaltungsort; [ggf. der Veranstalter der Veranstaltung, wenn wir nicht selbst veranstalten]. Eine
> Übermittlung in Drittländer findet [nicht / wie folgt] statt.
>
> **Speicherdauer:** Name, E-Mail-Adresse, Firma und Notiz Ihres Gästelisteneintrags löschen wir
> automatisch [Frist, z. B. „365 Tage“] nach dem Ende der Veranstaltung; erhalten bleiben nur die Anzahl der
> Tickets und der Status für unsere Auswertungen. Gedruckte Abhollisten vernichten wir spätestens [z. B. „am
> Tag nach der Veranstaltung“]. Die zu Ihrer Freikarte angelegte Bestellung über 0 € [löschen wir auf Anfrage
> bzw. nach Ablauf von … ; ggf. Aufbewahrung als Buchungsbeleg: Frist].
>
> **Ihre Rechte:** Auskunft, Berichtigung, Löschung, Einschränkung, Widerspruch gegen die Verarbeitung auf
> Grundlage berechtigter Interessen (Art. 21 DSGVO) und Beschwerde bei einer Aufsichtsbehörde
> ([AT: Datenschutzbehörde, Wien / DE: zuständige Landesbehörde]). Kontakt: [E-Mail].
>
> Pflichtangabe ist nur Ihr Name; ohne ihn können wir Sie nicht auf die Gästeliste setzen.

Hinweise zur Verwendung:

- **Zeitpunkt (Art. 14 Abs. 3 DSGVO):** innerhalb eines Monats, spätestens aber mit der ersten Mitteilung an
  den Gast. Mit E-Mail-Adresse: Die Ticketmail ist die erste Mitteilung. Da Gäste die normale Ticketmail
  (Typ `tickets`) erhalten, kann unter *Pass Captain → E-Mail-Vorlagen* ein kurzer Satz mit Link auf die
  Gästeinformation ergänzt werden – er steht dann in jeder Ticketmail, deshalb neutral formulieren
  (z. B. „Wurden Sie von Dritten angemeldet, finden Sie unsere Datenschutzinformation unter [URL]“).
  Alternativ eine eigene Mail vor der Ausstellung senden.
- **Ohne E-Mail-Adresse:** Information über die anmeldende Stelle weitergeben lassen (Pflicht in der
  Anfrage an Presse/Management/Sponsor vereinbaren, Textbaustein mitschicken) und an der Abholung aushängen
  bzw. auslegen. Ob die Ausnahme „unverhältnismäßiger Aufwand“ (Art. 14 Abs. 5 lit. b DSGVO) trägt, ist im
  Einzelfall zu prüfen und zu dokumentieren.
- **Anmeldende Stellen** (CSV-Lieferungen) sollten bestätigen, dass sie die Daten weitergeben dürfen und die
  Gäste informiert haben.
- **Datenschutzerklärung:** Der Vorschlag des Plugins enthält bereits einen Absatz „Gästelisten und
  Freikarten“, sobald es Gästelisten gibt (`src/Privacy/PolicyText.php`).

### 1.3 Ablauf: Abholliste und Ausdrucke nach dem Event vernichten

Die Abholliste („Gästeliste / Abholliste“, Admin-Seite Gästelisten → *Abholliste drucken*) enthält
alphabetisch Name, Firma, Liste, Personen inkl. Begleitung, Ticketart, **Kurzcodes der Tickets** (erste
8 Zeichen der Ticket-ID) und den Check-in-Stand. Dasselbe gilt sinngemäß für die Teilnehmerliste
(Termin → Teilnehmerliste drucken/CSV, mit voller Berechtigung inkl. E-Mail-Adressen und Antworten) und
für CSV-Exporte der Gästeliste.

Vorlage für eine Arbeitsanweisung:

1. **Vor dem Event:** Nur so viele Ausdrucke wie Abholstellen. Jedes Exemplar nummerieren
   („Exemplar 1/3“) und in einer Ausgabeliste vermerken: Event, Termin, Exemplar, ausgegeben an, Uhrzeit.
   Ausdrucke erst am Veranstaltungstag erstellen; Änderungen danach nur im System (Namenssuche), nicht
   handschriftlich nachtragen.
2. **Während des Events:** Liste nicht unbeaufsichtigt liegen lassen und nicht für Gäste einsehbar
   auslegen. Wenn möglich mit der Namenssuche am Gerät statt mit Papier arbeiten (zeigt keine E-Mail-Adressen).
3. **Nach Einlassende:** Verantwortliche Person sammelt alle Exemplare laut Ausgabeliste ein. Fehlende
   Exemplare sofort melden (möglicher Verlust personenbezogener Daten – Prüfung nach Art. 33 DSGVO durch
   die/den Datenschutzverantwortliche:n).
4. **Vernichtung:** spätestens [am nächsten Werktag] im Aktenvernichter (mindestens Sicherheitsstufe P-4
   nach DIN 66399 empfohlen) oder über einen Entsorgungsdienstleister mit Vernichtungsnachweis.
5. **Digitale Kopien:** heruntergeladene CSV-Dateien und PDF-Ausdrucke („Als PDF speichern“) auf
   Kassen-/Einlassrechnern und Mobilgeräten löschen (Downloads-Ordner, Papierkorb, Druckwarteschlange);
   Browser des Einlassgeräts schließen bzw. Website-Daten löschen (siehe OPERATIONS.md, Web-Scanner).
6. **Protokoll:** Vernichtung mit Datum, Exemplaren und Unterschrift festhalten:

   | Event / Termin | Exemplare ausgegeben | eingesammelt | vernichtet am | durch | Bemerkung |
   |---|---|---|---|---|---|
   | [ ] | [1–3] | [1–3] | [ ] | [ ] | [z. B. Exemplar 2 fehlt → gemeldet an …] |

Die gespeicherten Daten im System werden davon unabhängig mit der Teilnehmerfrist geleert (siehe 1.1).

### 1.4 Checkliste Gästelisten

- [ ] Rechtsgrundlage je Grund (`press`, `artist`, `sponsor`, `staff`, …) festgelegt und dokumentiert.
- [ ] Informationstext (1.2) angepasst, veröffentlicht und Weg der Information je Fall festgelegt.
- [ ] Anmeldende Stellen informiert und zur Weitergabe der Information verpflichtet.
- [ ] Frist für Teilnehmerangaben in den Einstellungen bewusst gesetzt; WP-Cron läuft zuverlässig.
- [ ] Arbeitsanweisung Abholliste (1.3) an Einlass-/Abholpersonal ausgegeben.
- [ ] Umgang mit der Gast-E-Mail an der 0-€-Bestellung geregelt (siehe 1.5).

### 1.5 Bekannte Lücke: E-Mail an der Freikarten-Bestellung

Der Löschlauf leert den Gästelisten-Eintrag und die Teilnehmerangaben der Tickets, nicht aber die
Käufer-E-Mail der zugehörigen 0-€-Bestellung (bezahlte Bestellungen sind vom automatischen Löschen
ausgenommen, weil sie Buchungsdaten sein können). Optionen: (a) Freikarten-Bestellungen nach dem Event
gesammelt über *Werkzeuge → Personenbezogene Daten löschen* mit der Gast-Adresse anonymisieren
(der Eraser „Pass Captain Gästelisten“ und der Bestell-Eraser laufen dabei gemeinsam), oder (b) im
Informationstext eine längere Speicherdauer nennen und begründen.

---

## 2. Rolle der Veranstalter und Wiederverkäufer

„Partner“ heißen im Plugin **Veranstalter** (Rolle `organizer`) bzw. **Wiederverkäufer** (Rolle
`reseller`), siehe [PARTNER-API.md](PARTNER-API.md). Für jeden Veranstalter/Wiederverkäufer muss der
Betreiber festlegen, ob er ihm gegenüber

- **eigenständig Verantwortlicher** ist und Daten an einen ebenfalls eigenständig Verantwortlichen
  übermittelt bzw. von ihm erhält,
- **Auftragsverarbeiter** des Veranstalters ist (oder umgekehrt), oder
- **gemeinsam Verantwortlicher** (Art. 26 DSGVO) mit ihm ist.

Das Plugin kennt diese Unterscheidung nicht; es setzt nur die technischen Grenzen unten durch.

### 2.1 Datenflüsse, die es tatsächlich gibt

| Weg | Richtung | Daten | Voraussetzung im Plugin |
|---|---|---|---|
| Veranstalter-API lesend: `/partner/events`, `/partner/reports` | Plattform → Veranstalter | Events, Termine, Ticketarten, verkaufte Mengen; Umsatz-/USt-/Auslastungsbericht (aggregiert) | Scopes `events:read`, `reports:read` |
| `/partner/orders`, `/partner/tickets` | Plattform → Veranstalter | Bestellungen (öffentliche ID, Status, Zahlungsanbieter, Zeitpunkte, Positionen) und Tickets (ID, Status, Ticketart, Termin) **ohne** E-Mail-Adressen | `orders:read` bzw. `tickets:read`; nur Positionen der eigenen Events; Wiederverkäufer nur eigene Verkäufe |
| dieselben Endpunkte mit `attendees:read` | Plattform → Veranstalter | zusätzlich **Käufer-E-Mail** (`customer_email`), **Teilnehmer-E-Mail** (`attendee_email`), bei eigenen API-Bestellungen `buyer_email`; Platzhalter und gelöschte Adressen als `null` | Scope `attendees:read` – personenbezogen |
| `/partner/checkins` | Plattform → Veranstalter | Check-ins (Ticket-ID, Ergebnis, Quelle, Zeit) **ohne** Mitarbeiter-IDs | `checkins:read` |
| `/partner/settlements`, Abrechnungsansicht im Admin | Plattform → Veranstalter | Abrechnungen: Positionen mit interner Bestell-ID, Event, Beträgen; Stammdaten des Veranstalters als Schnappschuss; keine Käuferdaten | `settlements:read` bzw. zugeordneter WordPress-Benutzer; nur abgeschlossene Abrechnungen |
| `/partner/sales/statement` (Wiederverkäufer-Abrechnung, CSV) | Plattform → Verkäufer | eigene API-Verkäufe mit Bestell-Kurzreferenz und **eigener externer Referenz** | `settlements:read` |
| Verkauf über die API: `POST /partner/orders` | **Verkäufer → Plattform** | Käufer-E-Mail (oder `anonymous_buyer`), **Teilnehmernamen** und ggf. Pflichtfelder, Rechnungsdaten (`billing`: Name, Anschrift, Firma, USt-IdNr.), externe Referenz, Zahlungsreferenz | `orders:write`; der Verkäufer kassiert selbst |
| Ticketcodes eigener API-Verkäufe | Plattform → Verkäufer | Ticket-Codes und -URLs (verschlüsselt gespeichert, erneut abrufbar) | `tickets:issue` |
| WordPress-Zugang der Rolle „Pass Captain Veranstalter“ (`wpcap_partner`) | Plattform → Personen des Veranstalters | die Rolle hat `wpcap_checkin` und `wpcap_view_reports`. **Mit Event-Zuweisung** (`wpcap_event_staff`) sehen diese Personen Scanner/Einlass, die Teilnehmerliste in der Basisansicht (Name, Ticketart, Status), Abholliste und Namenssuche der Gästelisten (Name, Firma, Kurzcodes) | Event-Zuweisung durch den Betreiber |
| Webhooks an Systeme des Veranstalters | Plattform → Empfänger | Ereignisse ohne E-Mail; mit `include_pii` auch E-Mail-Adressen | je Abonnement, siehe [WEBHOOKS.md](WEBHOOKS.md) |
| Audit-Log | intern | Key-ID, Veranstalter, Route, **gekürzte** Client-IP (IPv4 /24, IPv6 /48); keine E-Mails, Secrets oder Idempotenzschlüssel | immer |

Das **Verkaufsmodell** je Veranstalter ([SALES-MODELS.md](SALES-MODELS.md), `src/Partners/SalesModel.php`)
ist ein starkes Indiz für die Rollenverteilung:

- **Eigenhändler** (`own_name`, Standard): Die Plattform verkauft im eigenen Namen, stellt die
  Kundenrechnungen aus und ist Vertragspartner der Käufer:innen.
- **Vermittler** (`agent`): Die Plattform verkauft im Namen und für Rechnung des Veranstalters; die
  Kundenrechnungen tragen den Veranstalter als Aussteller.

### 2.2 Entscheidungshilfe

Für jeden Veranstalter/Wiederverkäufer die Fragen beantworten und das Ergebnis in der Tabelle 2.6
festhalten:

1. **Wer ist Vertragspartner der Käufer:innen?**
   Plattform (Eigenhändler) → Plattform ist für Verkauf und Einlass Verantwortlicher.
   Veranstalter (Vermittler) → weiter mit Frage 2.
2. **Entscheidet die Plattform selbst über Zwecke und wesentliche Mittel** (eigene AGB, eigener
   Kundenkontakt, eigene Auswertungen, eigene Marketingzwecke)?
   Ja → eigene Verantwortung (ggf. gemeinsame Verantwortung, wenn Zwecke gemeinsam festgelegt werden).
   Nein, sie verkauft nur nach Weisung des Veranstalters → **Plattform ist Auftragsverarbeiter des
   Veranstalters** für diese Verkäufe.
3. **Erhält der Veranstalter personenbezogene Daten** (`attendees:read`, Webhooks mit `include_pii`,
   Event-Zuweisung von Veranstalter-Personal, Gästelisten-Zugriff)?
   Nein (nur aggregierte Berichte, Abrechnungen, Bestellungen ohne E-Mail) → in der Regel keine
   Übermittlung personenbezogener Daten im engeren Sinn; Rolle trotzdem dokumentieren
   (öffentliche Bestell-/Ticket-IDs können pseudonyme Daten sein).
   Ja, und er nutzt sie für **eigene Zwecke** (eigene Einlasskontrolle, Kundenkommunikation, Statistik) →
   Übermittlung an einen eigenständig Verantwortlichen; Rechtsgrundlage für die Übermittlung nötig
   (Art. 6 Abs. 1 lit. b bzw. f DSGVO) und Information der Käufer:innen.
   Ja, aber er nutzt sie **nur für die Plattform** (z. B. Einlassdienst im Auftrag) →
   Veranstalter ist insoweit Auftragsverarbeiter der Plattform.
4. **Verkauft der Partner selbst über die API** (Wiederverkäufer, selbst verkaufende Veranstalter)?
   Dann erhebt er die Käuferdaten in seinem Shop/an seiner Kasse und **übermittelt** sie der Plattform. In
   der Regel ist er dafür eigener Verantwortlicher (er kassiert selbst und hat eigene Kunden); die Plattform
   wird für Ticketausstellung und Einlass ebenfalls Verantwortliche. Ausnahme: Er verkauft ausdrücklich
   im Namen und auf Weisung der Plattform → Auftragsverarbeiter der Plattform.

Faustregel (nicht verbindlich): Eigenhändler + Veranstalter ohne `attendees:read` → zwei getrennte
Verantwortliche ohne nennenswerte Übermittlung. Eigenhändler + `attendees:read` → Übermittlung an
eigenständig Verantwortlichen. Vermittler → Plattform als Auftragsverarbeiter des Veranstalters
(oder gemeinsame Verantwortung, wenn die Plattform die Kundenbeziehung mitgestaltet). Wiederverkäufer mit
API-Verkauf → eigenständig Verantwortlicher, der an die Plattform übermittelt.

### 2.3 Option A: eigenständig Verantwortliche – Checkliste und Klauseln

Checkliste:

- [ ] Rechtsgrundlage der Übermittlung je Datenfluss aus 2.1 festgelegt (z. B. Art. 6 Abs. 1 lit. b DSGVO für
      Einlass beim Veranstalter, lit. f für Statistik).
- [ ] Datenschutzerklärung nennt den Veranstalter als Empfänger (der Vorschlag des Plugins enthält dafür
      einen Absatz, sobald aktive Veranstalter existieren) bzw. den Wiederverkäufer als Quelle.
- [ ] Scopes auf das Nötige beschränkt; `attendees:read` nur, wenn die Übermittlung begründet ist.
- [ ] Event-Zuweisungen für Veranstalter-Personal geprüft (Teilnehmer- und Gästelisten sind dann sichtbar).
- [ ] Vereinbarung zur Datenweitergabe geschlossen (Klauseln unten).

Musterklauseln (Datenübermittlungsvereinbarung):

> 1. **Gegenstand.** [Plattform] stellt [Veranstalter] für dessen Veranstaltungen folgende Daten bereit:
>    [Liste aus 2.1, z. B. „Bestellungen und Tickets mit Käufer- und Teilnehmer-E-Mail über die
>    Veranstalter-API“]. Jede Partei ist für ihre Verarbeitung eigenständig Verantwortlicher im Sinne
>    von Art. 4 Nr. 7 DSGVO.
> 2. **Zweckbindung.** [Veranstalter] verwendet die Daten ausschließlich für [Durchführung der
>    Veranstaltung, Einlass, Information über Änderungen/Absagen]. Eine Nutzung für Werbung ist nur
>    zulässig, wenn [Veranstalter] dafür eine eigene Rechtsgrundlage hat.
> 3. **Transparenz.** [Veranstalter] informiert die Betroffenen über seine eigene Verarbeitung
>    (Art. 13/14 DSGVO). [Plattform] nennt [Veranstalter] in ihrer Datenschutzerklärung als Empfänger.
> 4. **Sicherheit.** API-Schlüssel sind vertraulich zu behandeln, nur an berechtigte Systeme zu geben und bei
>    Verdacht auf Missbrauch unverzüglich widerrufen zu lassen. [Optional: IP-Allowlist verpflichtend.]
> 5. **Betroffenenrechte und Vorfälle.** Die Parteien leiten Anfragen, die die andere Partei betreffen,
>    unverzüglich weiter und informieren sich gegenseitig über Datenschutzverletzungen, die die
>    übermittelten Daten betreffen, innerhalb von [48 Stunden].
> 6. **Löschung.** [Veranstalter] löscht die übermittelten Daten, sobald sie für den Zweck nicht mehr
>    erforderlich sind, spätestens [Frist] nach der Veranstaltung. Hinweis: Nach einer Datenschutzlöschung
>    liefert die API die Adresse als `null`; bereits abgerufene Kopien muss [Veranstalter] selbst löschen.
> 7. **Gegenrichtung (API-Verkauf).** Übermittelt [Veranstalter/Wiederverkäufer] Käufer-, Teilnehmer- oder
>    Rechnungsdaten an [Plattform], sichert er zu, dass er dazu berechtigt ist und die Betroffenen über
>    die Weitergabe an [Plattform] zur Ticketausstellung und Einlasskontrolle informiert hat.

### 2.4 Option B: Auftragsverarbeitung – Checkliste und Klauseln

Zuerst die **Richtung** festhalten:

- **B1: Plattform verarbeitet im Auftrag des Veranstalters** (typisch beim Vermittlermodell): Der
  Veranstalter ist Verantwortlicher, die Plattform schließt mit ihm einen Vertrag nach Art. 28 DSGVO und
  benennt ihre Unterauftragsverarbeiter (Hosting, E-Mail, Zahlungsanbieter soweit Auftragsverarbeiter,
  SMS, Fiskalanbieter …).
- **B2: Veranstalter/Wiederverkäufer verarbeitet im Auftrag der Plattform** (z. B. Einlassdienst mit
  eigenem Personal, Abruf der Teilnehmerdaten nur für die Plattform).

Checkliste Art. 28 Abs. 3 DSGVO (für beide Richtungen):

- [ ] Gegenstand, Dauer, Art und Zweck, Datenarten (aus 2.1) und Kategorien Betroffener (Käufer:innen,
      Teilnehmende, Gäste) beschrieben.
- [ ] Weisungsgebundenheit, Vertraulichkeit der Beschäftigten.
- [ ] Technische und organisatorische Maßnahmen (Anlage, Vorlage unten).
- [ ] Unterauftragsverarbeiter mit Genehmigungs-/Widerspruchsverfahren.
- [ ] Unterstützung bei Betroffenenrechten (Export/Löschung über die WordPress-Werkzeuge, manueller Ablauf
      nach 4.3) und bei Art. 32–36 DSGVO.
- [ ] Löschung oder Rückgabe nach Vertragsende (Hinweis: Rechnungen und Buchungsdaten unterliegen
      Aufbewahrungspflichten; das Plugin löscht Rechnungen, Korrekturen und Kassenschichten nur, wenn unter
      Einstellungen → Datenschutz eine Belegfrist in Jahren gesetzt ist – Standard: nie).
- [ ] Nachweis- und Kontrollrechte.

Vorlage Anlage TOM (Auszug, aus dem Plugin belegbar; um Hosting-Maßnahmen ergänzen):

> - Zugriff auf Veranstalter-API nur mit Schlüssel je Veranstalter; gespeichert werden nur Präfix und
>   SHA-256-Hash des Secrets; Scopes, Event- und Ticketart-Einschränkung, optionale IP-Allowlist,
>   Ablaufdatum und sofortiger Widerruf; Ratenlimits und Sperre nach 20 Fehlversuchen in 10 Minuten.
> - E-Mail-Adressen nur mit gesondertem Scope `attendees:read`; Wiederverkäufer sehen nur eigene Verkäufe.
> - Ticketcodes sonst nur als Hash; für API-Verkäufe verschlüsselt (libsodium secretbox).
> - Protokollierung aller schreibenden API-Aufrufe und fehlgeschlagenen Anmeldungen ohne personenbezogene
>   Inhalte, Client-IP gekürzt; Audit-Log 730 Tage.
> - Automatische Löschfristen gemäß OPERATIONS.md; Export und Löschung über die WordPress-Werkzeuge.
> - Rollen- und Event-Zuweisung für Admin-Zugriffe; Teilnehmerlisten nach Rolle minimiert.
> - [Hosting: Verschlüsselung in Transit (TLS), Backups, physische Sicherheit, Patchmanagement …]

### 2.5 Option C: gemeinsame Verantwortung (Art. 26 DSGVO)

Kommt in Betracht, wenn Plattform und Veranstalter Zwecke und Mittel gemeinsam festlegen (z. B. gemeinsame
Kundenkommunikation, gemeinsames Ticketing-Portal unter beiden Marken). Dann ist eine Vereinbarung nötig,
die festlegt, wer welche Informationspflichten erfüllt und wer Anlaufstelle für Betroffene ist; das
Wesentliche ist den Betroffenen zur Verfügung zu stellen (Art. 26 Abs. 2 DSGVO).

### 2.6 Dokumentation je Veranstalter

| Veranstalter / Wiederverkäufer | Rolle im Plugin | Verkaufsmodell | Scopes der Keys | Event-Zuweisungen von Personal | Webhooks mit PII | Ergebnis (A/B1/B2/C) | Vertrag (Datum, Ablage) | geprüft am / von |
|---|---|---|---|---|---|---|---|---|
| [ ] | `organizer`/`reseller` | Eigenhändler/Vermittler | [ ] | ja/nein | ja/nein | [ ] | [ ] | [ ] |

Bei jeder Änderung (neuer Scope, neues Verkaufsmodell, Event-Zuweisung) die Zeile prüfen.

---

## 3. Kassen-Schichtdaten: Mitbestimmung und Aufbewahrung

Die Kasse (Pass Captain → Kasse, `src/BoxOffice/`) ordnet jeden Vorgang einer angemeldeten Person zu.
Damit sind Kassierer:innen eindeutig identifizierbar, und Zählungen und Differenzen lassen sich ihnen
zurechnen. Das ist für die Kassenführung gewollt, kann aber Verhalten und Leistung der Beschäftigten
sichtbar machen.

### 3.1 Welche Daten über Kassierer:innen gespeichert werden

| Tabelle / Ort | Personenbezug | Inhalt |
|---|---|---|
| `wpcap_boxoffice_sessions` (Schicht) | `user_id` (öffnende Person), `closed_by` | Kasse, Öffnungs-/Schließzeit, Wechselgeld, gezählter Bestand, Soll, **Differenz bar und Karte**, Notiz, **Grund der Differenz** |
| `wpcap_boxoffice_shift_cashiers` | `user_id`, `added_by` | weitere Kassierer:innen der Schicht mit Zeitpunkt |
| `wpcap_boxoffice_counts` (Zählungen, nur einfügen) | `counted_by` | jede Zählung mit Betrag, Stückelung, Kartenterminal-Summe und dem zum Zeitpunkt gültigen Soll – auch Nachzählungen |
| `wpcap_boxoffice_movements` (Buchungen, nur einfügen) | `created_by` | Einlagen, Entnahmen, Korrekturen, Storno-/Erstattungsbuchungen mit Betrag, Grund und **Override-Grund** (Storno eingecheckter Tickets) |
| `wpcap_boxoffice_sales` | `cashier_id`, `voided_by` | jeder Verkauf mit Beleg, Zahlart, Betrag, gegebenem Betrag, Zeit; Storno mit Zeit und Person |
| `wpcap_boxoffice_handovers` | `from_user`, `to_user`, `decided_by` | Übergaben mit Betrag, gezähltem Betrag, **Differenz** und Grund |
| `wpcap_boxoffice_zreports` (nur einfügen, Prüfsummenkette) | `opened_by`, `closed_by`; in `totals` die Kassierer-IDs, Zählungen mit `counted_by`, alle Buchungen mit Person | Z-Bericht je Schicht |
| Kundenbeleg (Ausdruck) | je nach Einstellung „Bediener auf Kundenbelegen“: Bedienernummer (Standard), Initialen oder voller Name | |
| Audit-Log `wpcap_audit_logs` | `user_id` je Ereignis | `pos_session_opened`, `pos_count_submitted`, `pos_session_closed`, `pos_movement`, `pos_storno`, `pos_storno_override`, `pos_handover_*`, `pos_sale_failed` u. a. |
| Hooks (`wpcap_boxoffice_shift_closed` u. a.) | Z-Bericht inkl. Kassierer-IDs und Buchungen | an Integrationen, die der Betreiber anschließt |

Namen werden im Z-Bericht nicht gespeichert, sondern beim Anzeigen aus dem WordPress-Benutzerkonto gelesen;
ist das Konto gelöscht, erscheint nur „#ID“. Verwandt, aber nicht Kasse: Das Einlassprotokoll speichert
Mitarbeitende und Gerät je Scan (nach 365 Tagen entfernt).

Wer sieht was: Kassierer:innen ihre eigene Schicht und deren Z-Bericht; bei **Blindzählung** sehen sie Soll
und Differenz erst im Z-Bericht nach dem Schließen. Administratoren (`manage_options`) und Personen mit
`wpcap_view_finance` sehen alle Schichten, Soll-Bestände und Z-Berichte und können nachzählen.

**Löschung:** Schicht-, Zähl-, Buchungs-, Übergabe- und Z-Berichtsdaten werden vom Plugin **nicht
automatisch gelöscht** (Aufbewahrungspflicht, siehe 3.5). Das Audit-Log wird nach 730 Tagen gelöscht.

### 3.2 Mitbestimmung

**Österreich (ArbVG):**

- **§ 96 Abs. 1 Z 3 ArbVG** – Kontrollmaßnahmen und technische Systeme zur Kontrolle der
  Arbeitnehmer:innen, sofern sie die Menschenwürde berühren: nur mit Betriebsvereinbarung
  (zustimmungspflichtig, nicht ersetzbar).
- **§ 96a Abs. 1 Z 1 ArbVG** – Systeme zur automationsunterstützten Ermittlung, Verarbeitung und
  Übermittlung personenbezogener Daten der Arbeitnehmer:innen, die über allgemeine Angaben zur Person und
  fachliche Voraussetzungen hinausgehen: Betriebsvereinbarung nötig, Zustimmung durch die Schlichtungsstelle
  ersetzbar.
- **Ohne Betriebsrat:** Für Kontrollmaßnahmen im Sinne des § 96 Abs. 1 Z 3 ArbVG ist die Zustimmung der
  einzelnen Arbeitnehmer:innen erforderlich (§ 10 AVRAG).
- Die Einstufung der Kasse (personenbezogene Zuordnung von Differenzen, Blindzählung, Override-Gründe)
  nimmt der Betreiber mit Rechtsberatung und Betriebsrat vor.

**Deutschland (BetrVG):**

- **§ 87 Abs. 1 Nr. 6 BetrVG** – Einführung und Anwendung technischer Einrichtungen, die (nach der
  Rechtsprechung schon objektiv geeignet sind,) Verhalten oder Leistung der Arbeitnehmer:innen zu
  überwachen. Die personenbezogene Zuordnung von Verkäufen, Zählungen und Differenzen erfüllt das in aller
  Regel – Mitbestimmung des Betriebsrats vor Einführung.
- Ggf. zusätzlich § 87 Abs. 1 Nr. 1 BetrVG (Ordnung des Betriebs, z. B. Regeln zur Kassenübergabe) und
  Regelungen zur Mankohaftung.
- Rechtsgrundlage der Verarbeitung (Art. 6 Abs. 1 lit. b/c DSGVO, Art. 88 DSGVO i. V. m. einer
  Betriebsvereinbarung; die Tragfähigkeit von § 26 BDSG ist nach EuGH C-34/21 gesondert prüfen lassen).

### 3.3 Vorlage: Checkliste für eine Betriebsvereinbarung „Kassensystem“

1. **Geltungsbereich:** Kasse in Pass Captain (Kassen, Schichten, Zählungen, Übergaben, Z-Berichte),
   betroffene Standorte und Beschäftigtengruppen (auch Aushilfen, Ehrenamtliche, Fremdpersonal).
2. **Zweck:** ordnungsgemäße Kassenführung, Erfüllung steuerlicher Aufzeichnungs- und
   Aufbewahrungspflichten, Klärung von Kassendifferenzen, Missbrauchsschutz bei Freikarten/Stornos.
   **Ausschluss** anderer Zwecke, insbesondere Leistungsvergleiche zwischen Personen (Verkäufe pro
   Stunde o. Ä.) [ggf. ausdrücklich ausschließen].
3. **Datenkatalog:** Tabelle 3.1 als Anlage; Änderungen (neue Plugin-Version mit neuen Feldern) sind
   dem Betriebsrat vorzulegen.
4. **Zugriffsrechte:** wer `manage_options` und `wpcap_view_finance` hat (Namensliste/Funktion als Anlage);
   Kassierer:innen sehen nur eigene Schichten; Einstellung **Blindzählung** je Kasse festlegen.
5. **Kassendifferenzen:** Verfahren bei Differenzen (Pflichtgrund im System, Nachzählung nur durch
   Kassenverwaltung/Finanzen, Anhörung der betroffenen Person, Schwellenwerte, keine automatischen
   Konsequenzen), Mankoregelung.
6. **Übergaben:** Ablauf, Vier-Augen-Prinzip, Umgang mit Übergabedifferenzen und Stornierung durch
   Administratoren.
7. **Kundenbelege:** Bedienerangabe auf Belegen (Standard: Bedienernummer, keine Klarnamen).
8. **Auswertungen:** zulässige Berichte (Z-Bericht, Schichtliste, CSV) und Empfänger; keine Weitergabe von
   Einzeldaten an Dritte außer Steuerberatung, Betriebsprüfung, Fiskalanbieter (siehe 4.1).
9. **Integrationen:** angeschlossene Hooks/Exporte (`wpcap_boxoffice_shift_closed` usw.) und deren
   Empfänger; neue Anbindungen nur nach Information des Betriebsrats.
10. **Aufbewahrung und Löschung:** Fristen nach 3.5; danach Löschung oder Anonymisierung (das Plugin
    löscht nicht automatisch – Verantwortliche Person benennen); Audit-Log 730 Tage.
11. **Benutzerkonten:** persönliche Konten, keine Sammelkonten; Konten ausgeschiedener Personen erst nach
    Archivierung der Zuordnung ID → Name deaktivieren statt löschen (sonst zeigt der Z-Bericht nur „#ID“).
12. **Rechte der Beschäftigten:** Auskunft über eigene Schichtdaten (Export z. B. durch Finanzen aus
    Z-Bericht-CSV), Berichtigung über Korrekturbuchungen (Z-Berichte selbst sind unveränderlich).
13. **Kontrolle:** Einsichtsrecht des Betriebsrats, Evaluierung nach [12] Monaten, Kündigung/Nachwirkung.

### 3.4 Information der Beschäftigten (Art. 13 DSGVO)

Kassierer:innen sind vor dem ersten Einsatz zu informieren (Zweck, Daten nach 3.1, Empfänger,
Speicherdauer nach 3.5, Rechte). Der Text kann aus der Betriebsvereinbarung bzw. dem
Verarbeitungsverzeichnis (4.4, Zeile „Kasse – Schichtdaten“) abgeleitet werden.

### 3.5 Aufbewahrung der Z-Berichte (Information)

Z-Berichte, Zählprotokolle, Buchungen und Belege sind steuerlich aufbewahrungspflichtige Unterlagen.

| Land | Vorschrift | Frist | Beginn |
|---|---|---|---|
| Österreich | § 132 Abs. 1 BAO | **7 Jahre**; länger, solange sie für ein anhängiges Abgaben- oder sonstiges behördliches Verfahren von Bedeutung sind | Ende des Kalenderjahres, auf das sich die Unterlagen beziehen |
| Deutschland | § 147 Abs. 1, 3 AO (und § 257 HGB) | **10 Jahre** für Bücher und Aufzeichnungen; für Buchungsbelege seit 2025 grundsätzlich 8 Jahre (Viertes Bürokratieentlastungsgesetz) – die Einordnung der Z-Berichte klärt die Steuerberatung; einheitlich 10 Jahre ist die vorsichtige Wahl. Ablaufhemmung bei laufender Festsetzungsfrist (§ 147 Abs. 3 Satz 5 AO) | Schluss des Kalenderjahres (§ 147 Abs. 4 AO) |

Hinweise zur Umsetzung mit dem Plugin:

- Die Daten bleiben in der Datenbank; Z-Berichte, Zählungen und Buchungen werden nur eingefügt, nie
  geändert, und tragen eine Prüfsummenkette. Für Betriebsprüfungen müssen die Daten **maschinell
  auswertbar** bleiben (DE: § 147 Abs. 6 AO / GoBD, AT: § 131 BAO) – zusätzlich zum PDF/HTML regelmäßig
  die CSV-Exporte der Z-Berichte archivieren und die Datenbanksicherung über die Frist aufbewahren.
- Die Kasse des Plugins ist **keine Registrierkasse** (RKSV/TSE, siehe OPERATIONS.md). Die Pflichten
  einer zertifizierten Kasse (Datenerfassungsprotokoll, TSE-Export) bestehen daneben gesondert.
- Nach Ablauf der Frist muss der Betreiber selbst löschen bzw. anonymisieren. Ein Löschen einzelner alter
  Z-Berichte unterbricht die Prüfsummenkette in der Anzeige; vorher dokumentieren, welche Berichte bis wann
  gelöscht wurden.

---

## 4. Fiskalanbieter, Auskunft ohne E-Mail-Adresse, Verarbeitungsverzeichnis

### 4.1 Datenflüsse zum Fiskalanbieter

**Stand im Code:** Unter `src/Fiscalization/Providers/` liegen Adapter für **efsta EFR** (lokal oder Cloud)
und **fiskaltrust POS System API v2**. Sie implementieren ein Interface `Fiscalization\AdapterInterface`,
das es in diesem Stand noch nicht gibt; sie sind also nicht angebunden (Arbeitsliste A221). Eine
**fiskaly**-Anbindung ist hier nicht enthalten. In DE/AT sind Bar und Karte an der Kasse deshalb gesperrt,
bis ein Administrator bestätigt, dass die Verkäufe in einer eigenen zertifizierten Kasse erfasst werden
(`boxoffice_cash_confirmed`), oder eine Integration über den Filter `wpcap_boxoffice_fiscal_ready`
übernimmt. In der Praxis fließen Daten zum Fiskalanbieter daher über

- eine **eigene Integration** des Betreibers an den Kassen-Hooks (siehe OPERATIONS.md, Tabelle
  „Schnittstellen für Fiskalisierung“), oder
- eine **separate zertifizierte Kasse**, in die das Personal die Verkäufe erfasst, oder
- künftig die Adapter oben.

| Weg | Daten an den Anbieter |
|---|---|
| efsta-Adapter (`register`) | Zeitpunkt, Standort-/Terminalnummer, laufende Belegnummer, je Position Ticketart-ID, Bezeichnung „Ticket <ID>“, Steuergruppe, Betrag, Menge; Zahlart und Zahlungsgruppe; Steuerbeträge je Gruppe; **Steuernummer/USt-IdNr. des Verkäufers**; bei Cloud-Betrieb API-Key/Secret. Keine Käufer- oder Kassiererdaten. |
| fiskaltrust-Adapter (`sign`) | Belegreferenz (aus dem Idempotenzschlüssel), Zeitpunkt, je Position Bezeichnung, Betrag, USt-Satz, Geschäftsfall-Codes; Zahlart und Betrag; CashBox-ID/-Token, POS-System-ID. Keine Käufer- oder Kassiererdaten. |
| Hook `wpcap_boxoffice_shift_closed` | Z-Bericht mit Summen, **Kassierer-IDs**, Zählungen mit Person, allen Buchungen mit Person und Grund |
| Hooks `wpcap_boxoffice_cash_movement`, `wpcap_boxoffice_refund_booked` | Buchung mit Betrag, Grund, Beleg, handelnder Person (`created_by`), ggf. Override-Grund |
| Hook `wpcap_boxoffice_shift_opened`, Übergabe-Hooks | Schicht-/Kassen-ID bzw. Übergabe-ID (Details per Abfrage: übergebende/empfangende Person, Beträge) |

Personenbezug: Ist der Verkäufer eine natürliche Person (Einzelunternehmer:in), ist bereits die
Steuernummer personenbezogen. Gibt eine eigene Integration Kassierer-IDs oder -Namen weiter (z. B. als
Bediener im Fiskalbeleg) oder enthält ein Beleg Rechnungsdaten von Firmenkund:innen, verarbeitet der
Anbieter personenbezogene Daten von Beschäftigten bzw. Kund:innen.

### 4.2 Checkliste AV-Vertrag mit dem Fiskalanbieter

- [ ] Rolle geklärt: Cloud-TSE/Cloud-Fiskalisierung (fiskaly, efsta Cloud, fiskaltrust) → in der Regel
      Auftragsverarbeitung (Art. 28 DSGVO); lokale Installation (efsta EFR auf eigenem Rechner) → ggf.
      nur Wartungszugriff, dann AV für Fernwartung prüfen.
- [ ] Standard-AV-Vertrag (DPA) des Anbieters abgeschlossen und abgelegt; Anlage TOM geprüft.
- [ ] Unterauftragsverarbeiter und Speicherort (EU/EWR? Drittland → Art. 44 ff. DSGVO) dokumentiert.
- [ ] Datenumfang minimiert: keine Klarnamen von Kassierer:innen übermitteln, wenn eine Bedienernummer
      genügt; keine Käuferdaten in Positionsbezeichnungen.
- [ ] Aufbewahrung beim Anbieter (Journal, TSE-Export, Datenerfassungsprotokoll) mit den Fristen aus 3.5
      abgestimmt; Export bei Anbieterwechsel/Vertragsende geregelt.
- [ ] Zugangsdaten (API-Key/Secret, CashBox-Token) nur Administratoren zugänglich; Rotation bei
      Personalwechsel.
- [ ] Betriebsrat informiert, wenn Beschäftigtendaten übermittelt werden (siehe 3.3, Punkt 9).
- [ ] Bei separater zertifizierter Kasse: gleiche Prüfung für deren Hersteller/Cloud-Dienst.
- [ ] Eintrag im Verarbeitungsverzeichnis (4.4) und Empfänger in der Datenschutzerklärung ergänzt.

### 4.3 Manueller Auskunftsablauf für Bestellungen ohne E-Mail-Adresse

Die WordPress-Werkzeuge (*Werkzeuge → Personenbezogene Daten exportieren/löschen*) suchen nach der
E-Mail-Adresse. Verkäufe ohne Adresse erhalten eine **Platzhalteradresse** der reservierten Domain
`invalid.example`; an sie wird nie gemailt, und Veranstalter-API und Webhooks geben sie nicht aus.

| Platzhalter | Entsteht bei | So findet man die Bestellung |
|---|---|---|
| `boxoffice+<16 Zeichen>@invalid.example` | Kassenverkauf ohne E-Mail (nicht bei Zahlart Rechnung) | Belegnummer (z. B. `BON-…`) → Kasse → „Beleg suchen“ → Beleg öffnen: Zeile „Bestellung:“ zeigt die ersten 12 Zeichen der Bestellreferenz → *Bestellungen*, Suche „Bestellnummer oder E-Mail“ |
| `guestlist+<16 Zeichen>@invalid.example` | Gästelisten-Eintrag ohne E-Mail | Name → Gästelisten → Namenssuche bzw. Liste des Events (solange die Frist nicht abgelaufen ist); alternativ *Tickets*, Suche nach Teilnehmername; Ticket-Kurzcode von der Abholliste |
| `partner+<16 Zeichen>@invalid.example` | API-Verkauf mit `anonymous_buyer` | externe Referenz des Verkäufers → Wiederverkäufer-Abrechnung (CSV) des Monats: Spalten Bestell-Kurzreferenz (8 Zeichen) und externe Referenz → *Bestellungen*, Suche nach der Kurzreferenz; Anfrage ggf. über den Verkäufer, der die Kundendaten hat |
| `deleted+<24 Zeichen>@invalid.example` | bereits gelöschte/anonymisierte Bestellung | absichtlich nicht mehr einer Person zuordenbar; Auskunft: „keine Daten mehr gespeichert“ (außer Rechnungen, die über die Rechnungsnummer gefunden werden) |

Ablauf (Vorlage):

1. **Anfrage annehmen und dokumentieren** (Eingang, Frist ein Monat, Art. 12 Abs. 3 DSGVO).
2. **Identität und Zuordnung prüfen:** Die anfragende Person soll einen Nachweis vorlegen, dass sie die
   Bestellung getätigt hat – z. B. Original oder Foto des Kassenbelegs (Belegnummer, Datum, Betrag),
   Ticket-Kurzcode, Gästelisten-Einladung bzw. bei Verkäufen über Wiederverkäufer dessen Bestellbestätigung.
   Ohne ausreichenden Nachweis keine Herausgabe (Art. 11 Abs. 2, Art. 12 Abs. 6 DSGVO); Ergebnis festhalten.
3. **Bestellung finden** (Tabelle oben). Unter *Bestellungen* zeigt die Spalte Käufer die
   Platzhalteradresse – diese kopieren.
4. **Export erstellen:** *Werkzeuge → Personenbezogene Daten exportieren*, die Platzhalteradresse
   eintragen, **„Bestätigungs-E-Mail senden“ abwählen** (die Adresse kann keine Mail empfangen; die
   Identität wurde in Schritt 2 geprüft), Anfrage anlegen und den Export herunterladen. Der Export enthält
   Bestellung, Positionen, Tickets mit Teilnehmernamen, Einlassprotokoll, ggf. Rechnungsdaten, Verkauf über
   Veranstalter und Gästelisten-Eintrag.
5. **Ergänzen, was der Export nicht enthält:** bei Kassenverkäufen Belegdaten (Beleg erneut drucken),
   ggf. Daten bei Fiskalanbieter, Zahlungsterminal oder Wiederverkäufer (auf deren Verantwortung hinweisen).
6. **Sicher übergeben:** persönlich, per Post oder verschlüsselt; nicht an eine ungeprüfte E-Mail-Adresse.
   Danach die heruntergeladene Datei löschen.
7. **Löschung** (falls verlangt): *Werkzeuge → Personenbezogene Daten löschen* mit derselben
   Platzhalteradresse, ohne Bestätigungs-E-Mail. Tickets für kommende Termine werden zurückgestellt und nach
   dem letzten Termin anonymisiert; Rechnungen bleiben bis zum Ende der Aufbewahrungsfrist (die Meldung des
   Werkzeugs nennt beides).
8. **Abschluss dokumentieren** (Datum, Bearbeiter:in, was übermittelt/gelöscht wurde).

Fallback ohne Admin-Oberfläche (nur lesend, Tabellenpräfix anpassen):

```sql
-- Kassenverkauf über die Belegnummer
SELECT o.public_id, o.customer_email FROM wp_wpcap_boxoffice_sales s
  JOIN wp_wpcap_orders o ON o.order_id = s.order_id WHERE s.receipt_number = 'BON-…';
-- API-Verkauf über die externe Referenz des Verkäufers
SELECT o.public_id, o.customer_email FROM wp_wpcap_partner_orders po
  JOIN wp_wpcap_orders o ON o.order_id = po.order_id
 WHERE po.partner_id = [ID] AND po.external_reference = '[Referenz]';
```

### 4.4 Verarbeitungsverzeichnis (Art. 30 DSGVO) – Einträge aus dem Plugin

Die Tabelle leitet die Verarbeitungstätigkeiten aus den Daten des Plugins ab. Rechtsgrundlagen sind
Vorschläge; Fristen sind „wie implementiert“ (Filter `wpcap_privacy_retention_days` und Einstellungen
können sie ändern). Je Zeile ergänzen: Verantwortlicher/Kontakt, Datenschutzbeauftragte:r, TOM-Verweis,
Drittlandübermittlung.

| Tätigkeit | Zweck | Betroffene | Datenkategorien (Tabellen) | Rechtsgrundlage (Vorschlag) | Empfänger | Löschung wie implementiert |
|---|---|---|---|---|---|---|
| Ticketverkauf online | Vertragsabwicklung, Zustellung von Tickets und Bestellzugang | Käufer:innen | E-Mail, Bestellung, Positionen, Preise, Zahlungsstatus, AGB-Version, Locale (`wpcap_orders`, `wpcap_order_items`) | Art. 6 Abs. 1 lit. b, c | Hosting, E-Mail-Versand, Zahlungsanbieter, ggf. WooCommerce | unbezahlte: Teilnehmerangaben nach 1 Tag, E-Mail nach 90 Tagen ersetzt; bezahlte: keine automatische Löschung (Aufbewahrung, manuell) |
| Rechnungen, Gutschriften, Erstattungen | gesetzliche Belegpflichten, Erstattung | Käufer:innen | Rechnungsdaten (Firma, Name, Anschrift, UID), Rechnungs-/Gutschriftsnummern, Erstattungen, **Bankverbindung für Erstattungen** (`wpcap_invoices`, `wpcap_refunds`, Payout-Daten) | Art. 6 Abs. 1 lit. b, c | Steuerberatung, Zahlungsanbieter | keine automatische Löschung; 7 Jahre (AT) / bis 10 Jahre (DE) manuell |
| Teilnehmerdaten und Einlass | Durchführung, Einlasskontrolle, Missbrauchsschutz | Teilnehmende | Name, E-Mail, Zusatzfelder, Ticketstatus, Einlassprotokoll (Zeit, Ergebnis, Bereich) (`wpcap_tickets`, `wpcap_ticket_assignments`, `wpcap_checkins`) | Art. 6 Abs. 1 lit. b, f | Einlasspersonal, ggf. Veranstalter (2.1) | Name/E-Mail nach Teilnehmerfrist (Standard 365 Tage nach Terminende), Zusatzfelder nach Feldfrist; Protokoll bleibt ohne Namen |
| Einlass – Beschäftigtendaten | Nachvollziehbarkeit der Einlasskontrolle | Einlasspersonal | Benutzer- und Geräte-ID je Scan | Art. 6 Abs. 1 lit. b/f, ggf. BV | intern | nach 365 Tagen entfernt |
| Gästelisten und Freikarten | Ausstellung von Freikarten, Einlass, Nachweis des Anlasses | Gäste, Begleitpersonen, anmeldende Personen (Benutzer-IDs) | Name, E-Mail, Firma, Notiz, Anzahl, Status, Grund (`wpcap_guest_lists`, `wpcap_guest_entries`), 0-€-Bestellung | Art. 6 Abs. 1 lit. b/f, c | Einlasspersonal, ggf. Veranstalter | Eintrag nach Teilnehmerfrist geleert; Bestell-E-Mail siehe 1.5 |
| Warteliste | Platzangebot bei Freiwerden | Interessent:innen | E-Mail, Hash, Position, Angebot (`wpcap_waitlist`) | Art. 6 Abs. 1 lit. b | E-Mail-Versand | E-Mail 30 Tage nach Terminende geleert |
| Höchstmenge pro Person | Durchsetzung von Kaufbeschränkungen | Käufer:innen | HMAC-Hash von E-Mail/Konto, Zähler (`wpcap_alloc_limits`, Zählertabellen) | Art. 6 Abs. 1 lit. f | – | nach dem letzten Termin mit Teilnehmerfrist |
| Missbrauchsschutz | Schutz vor automatisierten Bestellversuchen | Besucher:innen | IP-Adresse nur als HMAC-Hash in kurzlebigen Zählern | Art. 6 Abs. 1 lit. f | – | mit Ablauf des Zählers |
| Kasse – Verkauf | Verkauf vor Ort, Belege | Käufer:innen vor Ort | optional E-Mail (sonst Platzhalter), bei Rechnung auf Firma Rechnungsdaten, Teilnehmernamen, Beleg (`wpcap_boxoffice_sales`) | Art. 6 Abs. 1 lit. b, c | ggf. Fiskalanbieter/zertifizierte Kasse | keine automatische Löschung (Aufbewahrung) |
| Kasse – Schichtdaten | ordnungsgemäße Kassenführung, Differenzklärung | Kassierer:innen | Tabelle 3.1 | Art. 6 Abs. 1 lit. b, c; Art. 88 i. V. m. BV | Steuerberatung, Betriebsprüfung, ggf. Fiskalanbieter | keine automatische Löschung; Fristen 3.5 |
| Veranstalter- und Wiederverkäuferverwaltung | Vertragsbeziehung, Abrechnung | Ansprechpersonen der Veranstalter | Name, Kontakt-E-Mail, Telefon, Rechnungsanschrift, USt-IdNr., Auszahlungsdaten, zugeordnete Benutzer (`wpcap_partners`, `wpcap_partner_settlements`) | Art. 6 Abs. 1 lit. b, c | Steuerberatung | Abrechnungen unveränderlich, Aufbewahrung; Stammdaten manuell |
| Veranstalter-API und API-Verkauf | Datenbereitstellung, Verkauf über Partner | Käufer:innen, Teilnehmende, Partner-Systeme | siehe 2.1; Key-Präfix/Hash, gekürzte IP im Audit-Log (`wpcap_partner_keys`, `wpcap_partner_orders`) | je nach Rolle (Abschnitt 2) | Veranstalter/Wiederverkäufer | Audit 730 Tage; Bestelldaten wie Ticketverkauf |
| Webhooks | Anbindung externer Systeme | Käufer:innen, Teilnehmende | Ereignisse, E-Mail nur mit `include_pii` | Art. 6 Abs. 1 lit. b/f, AV | Empfängersysteme | eigene Frist der Webhook-Zustellungen |
| Einwilligungen in Teilnehmerfeldern | Nachweis der Einwilligung (Art. 7 Abs. 1 DSGVO), Umsetzung des Widerrufs | Teilnehmende, Käufer:innen | Feld, Textfassung (Hash + Text), Aktion, Quelle, Locale, Zeitpunkt, HMAC-Hash der E-Mail, beim eigenen Checkout HMAC-Hash der IP (`wpcap_consent_log`, `wpcap_consent_texts`) | Art. 6 Abs. 1 lit. c, f (Nachweis) für den Zweck der Einwilligung lit. a | intern, ggf. per Hook angebundene Systeme | keine automatische Löschung; bei Löschanfrage Personenbezug entfernt (Abschnitt 5) |
| SMS-Benachrichtigungen | Information auf Wunsch | Käufer:innen | Mobilnummer, Einwilligung mit Textversion (`wpcap_sms_consents`) | Art. 6 Abs. 1 lit. a | SMS-Anbieter (z. B. Twilio, USA) | Einwilligung/Nachrichten nach Modulfristen |
| Wallet-Pässe | Ticket im Wallet, Aktualisierungen | Ticketinhaber:innen | Geräte-ID, Push-Token (Apple), Ticketangaben (Google) | Art. 6 Abs. 1 lit. b | Apple, Google (USA) | Registrierungen nach Teilnehmerfrist bzw. 1 Stunde nach Löschung |
| Nachrichtenwarteschlange | Zustellung von E-Mails/SMS | Empfänger:innen | Empfänger, Inhalt, Status (`wpcap_message_queue`) | wie Grundvorgang | E-Mail-/SMS-Versand | Inhalt nach 30 Tagen, Zeile nach 365 Tagen |
| Mitgliedspässe, Terminbuchungen, Umtausch | Leistungserbringung | Kund:innen | E-Mail, Pass-/Buchungsdaten, Umtauschvorgänge | Art. 6 Abs. 1 lit. b | E-Mail-Versand | mit Löschanfrage; sonst manuell |
| Audit-Log | Nachvollziehbarkeit von Verwaltungsvorgängen, Sicherheit | Beschäftigte, Administratoren | Benutzer-ID, Aktion, Objekt, Details (ohne Kunden-E-Mails) (`wpcap_audit_logs`) | Art. 6 Abs. 1 lit. c, f | intern | 730 Tage |
| Fiskalisierung | Erfüllung RKSV/KassenSichV | Verkäufer (bei natürlichen Personen), ggf. Kassierer:innen | siehe 4.1 | Art. 6 Abs. 1 lit. c | Fiskalanbieter (AV) | beim Anbieter nach dessen Aufbewahrung |

### 4.5 Checkliste A111

- [ ] AV-Vertrag mit dem Fiskalanbieter (und ggf. Anbieter der separaten zertifizierten Kasse) abgeschlossen.
- [ ] Ablauf 4.3 an Kasse, Gästelisten-Verantwortliche und Datenschutz-Postfach verteilt; Formular für
      Identitätsnachweis bereitgelegt.
- [ ] Verarbeitungsverzeichnis um die Zeilen aus 4.4 ergänzt (nur die tatsächlich genutzten Funktionen).
- [ ] Nach jedem Plugin-Update mit neuen Modulen oder Feldern die Tabellen 3.1, 2.1 und 4.4 prüfen.

---

## 5. Einwilligungen in Teilnehmerfeldern: Nachweis, Widerruf, Löschung

Ein Teilnehmerfeld vom Typ Checkbox kann im Event-Editor (Ticketart → Teilnehmerfelder) als
**Einwilligung** markiert werden; der Zweck ist dann Pflicht und wird beim Ankreuzen angezeigt. Für diese
Felder gilt:

### 5.1 Was gespeichert wird

| Daten | Wo | Zweck |
|---|---|---|
| Bezeichnung und Zweck genau so, wie sie angezeigt wurden; Version = SHA-256 über beides | `wpcap_consent_texts` (je Feld und Fassung eine Zeile) | Nachweis, welchem Text zugestimmt wurde. Jede Änderung von Bezeichnung oder Zweck erzeugt eine neue Fassung; alte Fassungen bleiben. |
| Jede Erteilung und jeder Widerruf als eigene Zeile: Ticket, Bestellung, Feld, Textfassung, Aktion (`granted`/`withdrawn`), Quelle (`checkout`, `woocommerce`, `box_office`, `api`, `portal`, `ticket_page`, `admin`, `exchange`, `transfer`, `holder_change`, `privacy_erasure`), Locale, Zeitpunkt (UTC), handelnde Benutzer-ID (Kasse/Verwaltung), Notiz der Verwaltung | `wpcap_consent_log` (nur anhängen) | Nachweis (Art. 7 Abs. 1 DSGVO); der aktuelle Stand ist immer die letzte Zeile je Ticket und Feld. |
| Personenbezug: HMAC-SHA-256 der E-Mail-Adresse (Teilnehmer, sonst Käufer), Schlüssel aus dem WordPress-Auth-Salt | `subject_hash` | Zuordnung des Nachweises zu einer Person, ohne die Adresse im Protokoll zu speichern. |
| IP-Adresse **nur als HMAC-Hash** (eigener Schlüssel, Nonce-Salt), nur beim eigenen Checkout und bei Selbstbedienung | `ip_hash` | Zusatzindiz für den Nachweis; die IP selbst wird nie gespeichert. Ein geänderter Salt macht den Hash unprüfbar, schadet dem Nachweis sonst nicht. |

Bei Bestellungen im Checkout wird die Einwilligung beim Absenden erfasst (Zeitpunkt, Textfassung) und mit
der Ticketausgabe ins Protokoll geschrieben. Unbezahlte, verfallene Bestellungen erzeugen keinen Eintrag.

### 5.2 Widerruf

- **Selbstbedienung:** In der Bestellübersicht (Link aus der Bestellmail) und auf der Ticketseite gibt es
  die Karte „Einwilligungen“ mit Stand und Button „Einwilligung widerrufen“ (bzw. „erteilen“). Das Formular
  ist an den Zugangslink gebunden und mit einer Nonce geschützt. Übertragene Tickets verwaltet nur noch die
  neue Person über ihre Ticketseite.
- **Verwaltung:** Bestellung öffnen → Abschnitt „Einwilligungen“ → „Widerruf erfassen“ (z. B. bei Widerruf
  per E-Mail oder Telefon; Notiz optional). Jede Änderung steht zusätzlich im Audit-Log.
- **Automatisch beendet** (als Widerruf protokolliert) werden die Einwilligungen eines Tickets bei
  Übertragung (`transfer`), Wechsel der teilnehmenden Person über die Bestellübersicht (`holder_change`) und
  bei der Datenschutz-Löschung (`privacy_erasure`). Ein Ticketumtausch übernimmt erteilte Einwilligungen
  auf das neue Ticket (`exchange`).
- Auch eine **Pflicht-Einwilligung** kann widerrufen werden. Das Plugin sperrt das Ticket deshalb nicht; ob
  die Teilnahme ohne die Einwilligung möglich ist, entscheidet der Veranstalter.
- Angebundene Systeme (z. B. Newsletter) reagieren über die Hooks `wpcap_consent_granted` und
  `wpcap_consent_withdrawn` (siehe [HOOKS.md](HOOKS.md)); der Betreiber muss sicherstellen, dass ein Widerruf
  dort ebenfalls umgesetzt wird.

### 5.3 Entscheidung: Datenschutz-Löschung und Nachweis

Bei einer Löschanfrage (WordPress → Werkzeuge → Personenbezogene Daten löschen) werden die Antworten der
Teilnehmerfelder gelöscht, laufende Einwilligungen als Widerruf (`privacy_erasure`) beendet und in **allen**
Protokollzeilen der Tickets der Personenbezug entfernt (E-Mail-Hash, IP-Hash, Notiz der Verwaltung).
**Erhalten bleiben** Feld, Textfassung, Aktion, Quelle, Locale und Zeitpunkt sowie die Ticket-/Bestellnummer
(die Tickets selbst sind dann ebenfalls anonymisiert).

Begründung: Der Verantwortliche muss nachweisen können, dass eine Verarbeitung auf einer Einwilligung beruhte
(Art. 7 Abs. 1 DSGVO), und sich gegen Ansprüche verteidigen können (Art. 17 Abs. 3 lit. b und e DSGVO). Dafür
genügt nach der Löschung der minimale, nicht mehr personenbezogene Nachweis, dass zu einem Ticket zu einem
Zeitpunkt einem bestimmten Text zugestimmt bzw. widersprochen wurde. Die Zuordnung zur Person wird nicht
länger gebraucht und deshalb entfernt. Wer eine längere personenbezogene Nachweisführung braucht (z. B.
laufende Rechtsstreitigkeit), muss die Löschanfrage vorher prüfen und ggf. zurückstellen.

Der Export personenbezogener Daten (WordPress → Werkzeuge → Personenbezogene Daten exportieren) enthält je
Ticket den Verlauf „Einwilligungen“ mit Text, Zweck, Textfassung, Aktion, Quelle, Locale und Zeitpunkt.

Die Protokolle haben **keine automatische Löschfrist** (sie enthalten nach Ablauf der Teilnehmerfrist nur
noch den Hash des Personenbezugs). Die Antworten der Felder selbst folgen weiter der Aufbewahrungsdauer des
Feldes; das Löschen der Antwort durch die Frist beendet die Einwilligung nicht.

### 5.4 CSV-Export je Event

Event öffnen → „Einwilligungen exportieren (CSV)“ (Berechtigung Bestellungen verwalten für das Event): alle
Erteilungen und Widerrufe mit Textfassung, Quelle, Locale, gekürztem Personen-Hash und aktuellem Stand. Jeder
Export wird im Audit-Log (`consents_exported`) vermerkt. Die Datei enthält keine E-Mail-Adressen; sie wie
andere Teilnehmerexporte behandeln und nach Gebrauch löschen.

### 5.5 Checkliste A116

- [ ] Nur echte Einwilligungen als „Einwilligung“ markiert (nicht z. B. Bestätigungen wie „Ich bin volljährig“).
- [ ] Zweck vollständig und verständlich formuliert; Hinweis auf Widerruf in der Datenschutzerklärung.
- [ ] Angebundene Systeme setzen `wpcap_consent_withdrawn` um (Newsletter abmelden usw.).
- [ ] Ablauf für Widerrufe per E-Mail/Telefon festgelegt (Erfassung in der Bestellung).
