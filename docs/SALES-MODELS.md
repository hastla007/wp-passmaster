# Verkaufsmodelle: Eigenhändler und Vermittler

Pass Captain unterstützt je Veranstalter eines von zwei Verkaufsmodellen. Das Modell entscheidet,
**wer** gegenüber den Ticketkäufern leistet und Rechnungen ausstellt und **welche Belege** die Plattform
monatlich mit dem Veranstalter austauscht.

> **Wichtig:** Diese Seite beschreibt die technische Umsetzung und die Rechtsgrundlagen, an denen sie
> sich orientiert. Sie ersetzt keine Steuerberatung. Wahl des Modells, Vertragsgestaltung und
> Belegtexte sind vor dem Produktivbetrieb mit der Steuerberatung abzustimmen (offene Fragen siehe unten).

## Überblick

| | Eigenhändler (`own_name`, Standard) | Vermittler (`agent`) |
|---|---|---|
| Verkauf an Kunden | Plattform im eigenen Namen (für Rechnung des Veranstalters) | Plattform im Namen und für Rechnung des Veranstalters |
| Kundenrechnung | Plattform als Rechnungssteller, Nummernkreis der Plattform (`WPC-…`) | **Veranstalter** als Rechnungssteller, eigener Nummernkreis je Veranstalter (`TAG-…` bzw. `V{ID}-…`), Zusatz „Vermittelt durch {Plattform}“ |
| Rechnungskorrekturen | Serie der Plattform (`GS-…`) | Serie des Veranstalters (`{Präfix}-K-…`) |
| USt auf Ticketumsatz schuldet | Plattform | Veranstalter |
| Monatlicher Beleg an Veranstalter | **Gutschrift** (Teil A, Serie `credit-self`, `GU-…`) + **Rechnung über Plattformgebühren** (Teil B, `PA-…`) + Verrechnung (Teil C) | **Provisionsrechnung** (`PA-…`) + informative Aufstellung der vereinnahmten Ticketerlöse |
| Events ohne Veranstalter | immer Eigenhändler (Plattform verkauft) | – |

Einstellung: *Pass Captain → Veranstalter → (Veranstalter) → Verkaufsmodell und Rechnungsangaben*.
Die Liste der Veranstalter zeigt das Modell und markiert unvollständige Rechnungsangaben; die
Bestellansicht zeigt das Verkaufsmodell bzw. den Rechnungssteller der Bestellung.

## Rechtsgrundlagen (Orientierung)

### Eigenhändler / Leistungskommission

- **DE:** § 3 Abs. 11 UStG (Dienstleistungskommission): Wer im eigenen Namen für fremde Rechnung eine
  sonstige Leistung besorgt, wird so behandelt, als hätte er sie selbst erhalten und erbracht. Es liegen
  zwei Leistungen vor: Veranstalter → Plattform und Plattform → Kunde.
- **AT:** § 3a Abs. 4 UStG (Besorgungsleistung), gleiche Wirkung.
- Die Plattform stellt die Kundenrechnungen aus (bisheriges Verhalten).
- Über die Leistung des Veranstalters an die Plattform rechnet die Plattform mit einer **Gutschrift**
  ab: § 14 Abs. 2 Satz 2 UStG (Abrechnung durch den Leistungsempfänger, vorherige Vereinbarung
  erforderlich), Pflichtangabe „Gutschrift“ nach § 14 Abs. 4 Satz 1 Nr. 10 UStG; AT: § 11 Abs. 7 UStG.
  Der Begriff „Gutschrift“ ist für dieses Abrechnungsverfahren reserviert; Korrekturen von
  Kundenrechnungen heißen im Plugin „Rechnungskorrektur“.
- Die Plattformgebühr (Provision und Ticketgebühren) wird separat in Rechnung gestellt (Teil B).

### Vermittler / Agentur

- Die Plattform vermittelt im fremden Namen; die Leistung an den Kunden erbringt der Veranstalter.
  Die Kundenrechnung muss den Veranstalter als leistenden Unternehmer ausweisen (§ 14 Abs. 4 Nr. 1, 2
  UStG: Name, Anschrift, USt-IdNr. oder Steuernummer) und eine fortlaufende Nummer aus einem
  Nummernkreis **des Veranstalters** tragen (§ 14 Abs. 4 Nr. 4 UStG). Die Plattform darf die Rechnung im
  Auftrag ausstellen (§ 14 Abs. 2 Satz 4 UStG).
- Die Plattform erbringt nur ihre Vermittlungsleistung an den Veranstalter und stellt diese mit der
  **Provisionsrechnung** in Rechnung.
- **Reverse Charge**: Ist der Veranstalter Unternehmer in einem anderen EU-Mitgliedstaat und hat er
  eine gültige USt-IdNr., liegt der Leistungsort der Vermittlungsleistung (B2B-Grundregel) beim
  Veranstalter; die Steuer schuldet der Leistungsempfänger. Rechtsgrundlagen: § 3a Abs. 2, § 13b Abs. 1,
  Abs. 5 UStG, § 14a Abs. 1, 5 UStG (Hinweis „Steuerschuldnerschaft des Leistungsempfängers“); Art. 44,
  196 MwStSystRL; AT: § 3a Abs. 6, § 19 Abs. 1 UStG. Die Rechnung enthält dann keine USt, beide
  USt-IdNrn. und den Hinweis **„Steuerschuldnerschaft des Leistungsempfängers / Reverse Charge“**.
  Bei innergemeinschaftlichen B2B-Dienstleistungen ist eine Zusammenfassende Meldung abzugeben (§ 18a UStG).

## Was das Plugin tut

### Veranstalter-Felder

| Feld | Verwendung |
|---|---|
| Verkaufsmodell | `own_name` (Standard) oder `agent` |
| Rechtlicher Name | Firmenname auf Rechnungen/Gutschriften (sonst der Anzeigename) |
| Rechnungsanschrift, USt-IdNr. | aus den Stammdaten |
| Land (ISO) | Reverse-Charge-Prüfung; leer = Land der Plattform |
| Steuernummer | Pflichtangabe, wenn keine USt-IdNr. vorhanden ist |
| USt-IdNr. qualifiziert bestätigt am | Dokumentation der Prüfung (BZSt/VIES); ohne Datum warnt die Admin-Ansicht |
| Präfix Rechnungsnummern | nur Buchstaben/Ziffern, eindeutig, nicht gleich den Plattform-Präfixen; leer = `V{ID}` |
| Kleinunternehmer + Hinweistext | keine USt auf Veranstalter-Belegen; Standardtext § 19 UStG, für AT z. B. „Umsatzsteuerbefreit – Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG“ |

Plattformdaten (Einstellungen → Rechnungssteller): Name, Anschrift, USt-IdNr., **Steuernummer**,
**Land** (Standard DE).

### Kundenrechnungen

- Rechnungssteller wird bei der Ausstellung ermittelt: Gehören alle Tickets einer Bestellung zu Events
  eines Vermittler-Veranstalters, wird die Rechnung im Namen dieses Veranstalters ausgestellt (Schnappschuss
  der Veranstalterdaten, Nummer aus der Serie `invoice-org-{ID}`, Präfix des Veranstalters). Sonst stellt
  die Plattform aus (Eigenhändler-Veranstalter, Events ohne Veranstalter).
- Der Beleg enthält „Vermittelt durch {Plattform}, {Anschrift} (USt-IdNr.)“ und den Hinweis, dass die
  Plattform im Namen und für Rechnung des Veranstalters handelt.
- **Gemischte Bestellungen** (Vermittler-Veranstalter zusammen mit anderen Veranstaltern oder
  Plattform-Events) werden **nicht aufgeteilt**:
  - Der Checkout lehnt solche Warenkörbe ab („Bitte für jeden Veranstalter separat bestellen“), auch bei
    WooCommerce-Bestellungen, die über das Plugin angelegt werden. Abschaltbar mit dem Filter
    `wpcap_refuse_mixed_seller_orders` (nicht empfohlen).
  - Bestehende gemischte Bestellungen (z. B. vor dem Update oder vor einem Modellwechsel angelegt) werden
    bei der Rechnungsstellung mit einer klaren Fehlermeldung abgelehnt (`wpcap_invoice_mixed_sellers`,
    HTTP 409, Audit-Eintrag `invoice_refused`); die Bestellansicht zeigt den Hinweis. Keine Nummer wird
    verbraucht. Diese Rechnungen sind außerhalb des Plugins je Veranstalter zu erstellen oder die
    Bestellung ist zu stornieren und getrennt neu anzulegen.
- Weitere Ablehnungsgründe (ohne Nummernverbrauch): Veranstalter ohne Anschrift oder ohne
  USt-IdNr./Steuernummer (`wpcap_invoice_organizer_incomplete`); Kleinunternehmer-Veranstalter mit
  Ticketarten mit USt-Satz > 0 % (`wpcap_invoice_small_business_vat`, sonst unberechtigter Steuerausweis
  nach § 14c UStG).
- Rechnungskorrekturen folgen immer dem Rechnungssteller und Nummernkreis der korrigierten Rechnung.
- Der Buchhaltungsexport enthält die Spalten `Verkaufsmodell` und `Rechnungssteller`. Belege im Modell
  Vermittler sind **keine Umsätze der Plattform** und in der Buchhaltung der Plattform als
  fremde Belege (Durchlaufposten/Verrechnungskonto Veranstalter) zu behandeln.
- Ausgestellte Rechnungen sind unveränderlich; spätere Änderungen am Veranstalter wirken nur auf neue
  Rechnungen.

### Monatliche Abrechnung

Der Belegtyp wird beim Abschließen festgeschrieben (Spalte `document_type`, Schnappschuss von Plattform
und Veranstalter):

**Vermittler – Provisionsrechnung (`commission_invoice`)**

- Rechnungssteller Plattform, Empfänger Veranstalter, Nummer aus Serie `partner` (`PA-…`).
- Positionen: Provision (x % vom Netto-Ticketumsatz), Ticketgebühren, Nettobetrag, USt (Satz laut
  Veranstalter) oder Reverse Charge (0 € USt + Hinweis), Rechnungsbetrag; Leistungszeitraum = Monat.
- Darunter eine als „keine Rechnung“ gekennzeichnete **informative Aufstellung** der im Namen des
  Veranstalters vereinnahmten Ticketerlöse und des Auszahlungsbetrags
  (`vereinnahmt brutto − Provisionsrechnung brutto`).

**Eigenhändler – Gutschrift + Gebührenrechnung (`self_billing`)**

- **Teil A – Gutschrift `GU-…`** (eigener lückenloser Nummernkreis `credit-self`, Präfix einstellbar):
  leistender Unternehmer = Veranstalter (Name, Anschrift, USt-IdNr./Steuernummer), Aussteller und
  Leistungsempfänger = Plattform, Hinweis auf das Gutschriftsverfahren, Leistung und Leistungszeitraum,
  Netto/USt/Brutto **je Steuersatz** (Netto-Ticketumsatz nach Erstattungen, USt zum selben Satz).
  Keine USt bei Kleinunternehmern (mit Hinweistext) oder wenn der Veranstalter in einem anderen
  EU-Staat ansässig ist und die Plattform eine USt-IdNr. hat (Reverse Charge, Hinweis).
- **Teil B – Rechnung `PA-…` über Plattformgebühren** (Provision + Ticketgebühren, USt oder Reverse Charge).
- **Teil C – Verrechnung:** Gutschriftsbetrag − Rechnungsbetrag = Auszahlungsbetrag (negativ =
  Forderung an den Veranstalter).
- Abschließen wird verweigert, solange beim Veranstalter Anschrift oder USt-IdNr./Steuernummer fehlen
  (`wpcap_settlement_supplier_incomplete`, keine Nummer verbraucht).

Für beide gilt: Nummern werden in derselben Datenbank-Transaktion wie das Abschließen reserviert
(lückenlos je Serie und Jahr, Rollback bei Fehler), Gutschrifts- und Belegnummern sind per
Unique-Index eindeutig, abgeschlossene/ausgezahlte Belege können nicht neu berechnet, erneut
abgeschlossen oder gelöscht werden. Ein späterer Wechsel des Verkaufsmodells oder der Konditionen wirkt
nur auf Entwürfe und künftige Abrechnungen. Vor dem Update abgeschlossene Abrechnungen bleiben
unverändert („Veranstalter-Abrechnung“, `document_type` leer).

### Nummernkreise

| Serie | Präfix (Beispiel) | Beleg |
|---|---|---|
| Jahreszähler `wpcap_invoice_sequences` | `WPC-2026-000001` | Kundenrechnung der Plattform |
| `credit` | `GS-2026-000001` | Rechnungskorrektur der Plattform |
| `invoice-org-{ID}` | `TAG-2026-000001` / `V7-2026-000001` | Kundenrechnung im Namen des Veranstalters |
| `credit-org-{ID}` | `TAG-K-2026-000001` | Rechnungskorrektur im Namen des Veranstalters |
| `partner` | `PA-2026-000001` | Provisionsrechnung / Rechnung über Plattformgebühren |
| `credit-self` | `GU-2026-000001` | Gutschrift (Gutschriftsverfahren) |

Die Spalte `series` der Tabelle `wpcap_document_sequences` wurde auf 40 Zeichen erweitert (Schema 3.5.0).

## Pflichten des Betreibers

1. **Verträge mit Veranstaltern**, die das gewählte Modell eindeutig festlegen (Kommissions- bzw.
   Vermittlungsvertrag, Auftreten gegenüber Kunden, Inkassovollmacht, Abrechnungsrhythmus,
   Behandlung von Erstattungen). Das Auftreten im Shop (AGB, Impressum, Checkout-Hinweise, Ticket- und
   Bestelltexte) muss zum Modell passen – beim Vermittler muss für Kunden erkennbar sein, dass der
   Vertrag mit dem Veranstalter zustande kommt.
2. **Gutschriftsvereinbarung** (Eigenhändler): Das Gutschriftsverfahren setzt eine vorherige
   Vereinbarung mit dem Veranstalter voraus (§ 14 Abs. 2 Satz 2 UStG; AT § 11 Abs. 7 UStG). Der
   Veranstalter kann einer Gutschrift widersprechen; dann verliert sie ihre Wirkung als Rechnung.
3. **Rechnungsausstellung im Auftrag** (Vermittler): schriftliche Vereinbarung, dass die Plattform
   Rechnungen im Namen des Veranstalters ausstellt, inkl. Nummernkreis-Präfix und Übermittlung der
   Belege an den Veranstalter (Aufbewahrungspflicht liegt beim Veranstalter).
4. **USt-IdNr.-Prüfung**: USt-IdNrn. ausländischer Veranstalter qualifiziert bestätigen (BZSt bzw.
   VIES), Ergebnis aufbewahren und das Prüfdatum im Veranstalter erfassen. Das Plugin prüft nur das
   Format; Reverse Charge wird angewendet, sobald das Format zum Land passt.
5. **Stammdaten aktuell halten** (rechtlicher Name, Anschrift, Steuernummer/USt-IdNr.,
   Kleinunternehmerstatus) – Belege übernehmen die Daten als unveränderlichen Schnappschuss.
6. **Steuersätze der Ticketarten** passend zum Modell und Veranstalter pflegen (Kleinunternehmer: 0 %).
7. **Zusammenfassende Meldung** und Umsatzsteuer-Voranmeldung für Reverse-Charge-Leistungen;
   Buchung der Vermittler-Erlöse als fremde Gelder.
8. **E-Rechnung:** Ab 2025/2027/2028 gelten in DE gestaffelte Pflichten zur E-Rechnung im B2B-Bereich
   (§ 14 Abs. 1 UStG n. F.). Die HTML/PDF-Belege des Plugins sind keine strukturierten E-Rechnungen
   (XRechnung/ZUGFeRD) – Gutschriften, Provisions- und Gebührenrechnungen an Veranstalter sind B2B.

## Offene Fragen für die Steuerberatung

- Ist die Bemessungsgrundlage der Gutschrift (Eigenhändler) der volle Netto-Ticketumsatz mit separater
  Gebührenrechnung (so umgesetzt) oder – üblicher bei der Leistungskommission – der um die Provision
  gekürzte Betrag (dann keine separate Provisionsrechnung)?
- Leistungsort bei Eintrittsberechtigungen: B2B-Eintrittsberechtigungen sind am Veranstaltungsort
  steuerbar (§ 3a Abs. 3 Nr. 5 UStG / Art. 53 MwStSystRL). Reverse Charge in der Gutschrift wird derzeit
  nach Sitz des Veranstalters ermittelt, nicht nach Veranstaltungsort – bei Events im Ausland ggf.
  Registrierungspflicht im Veranstaltungsland.
- Vermittlungsleistungen im Zusammenhang mit Eintrittsberechtigungen: gilt für die Provision die
  B2B-Grundregel (§ 3a Abs. 2) oder ein Sondertatbestand?
- Reicht ein formaler Check plus Prüfdatum, oder soll Reverse Charge erst nach eingetragener
  qualifizierter Bestätigung greifen?
- Rechnungskorrekturen der Plattform tragen standardmäßig das Präfix `GS` und werden im
  Buchhaltungsexport als „Gutschrift“ bezeichnet – sollte das wegen der Verwechslungsgefahr mit der
  Gutschrift i. S. d. § 14 Abs. 2 Satz 2 UStG geändert werden?
- Negative Monatswerte (mehr Erstattungen als Verkäufe): Gutschrift mit negativem Betrag oder
  Korrekturbeleg zu einer früheren Gutschrift?
- Kleinunternehmer im Eigenhändler-Modell: Wer trägt die USt auf den Kundenumsatz wirtschaftlich
  (umgesetzt: Gutschrift = Netto-Umsatz ohne USt)?
- Österreich: Formulierung der Hinweise (Kleinunternehmer § 6 Abs. 1 Z 27 UStG, Reverse Charge
  § 19 Abs. 1 UStG) und Anforderungen an Gutschriften nach § 11 Abs. 7, 8 UStG.

## Verkaufsregeln im Checkout

Unabhängig vom Verkaufsmodell gelten im Checkout (`Orders::create()`) die folgenden Regeln. Code:
`src/Sales/`, Modul `Modules/SalesRules`, Tabellen `wpcap_price_rules`, `wpcap_buyer_limits`,
`wpcap_consents` (Kernschema 3.8.0). Steuern: siehe [TAX-PROFILES.md](TAX-PROFILES.md).

### Automatische Preisregeln

*Event bearbeiten → „Automatische Preisregeln“* (Terminserien zusätzlich unter „Serientermine“).

- Arten: **Frühbucher** (Buchung vor einem Zeitpunkt), **Last-Minute** (ab X Stunden vor Beginn),
  **Nachfrage** (ab X % verkauft oder reserviert), **Wochentag/Zeitfenster** (Beginn des Termins in
  Ortszeit). Preis fest (brutto) oder prozentual zum gespeicherten Preis (kaufmännisch gerundet).
- Geltungsbereich: ganzes Event, ein Termin, eine Ticketart und/oder Ticketarten eines Namens.
- Grenzen (UTC verglichen): Frühbucher gilt solange `jetzt < bis`; Last-Minute ab `Beginn − Stunden`
  (inklusive); Nachfrage ab `verkauft % ≥ Schwelle` (bezahlte Einheiten + aktive Reservierungen
  anderer Bestellungen gegen das Kontingent der Ticketart, sonst des Termins); Zeitfenster „von“
  inklusive, „bis“ exklusive, `von > bis` geht über Mitternacht.
- Von allen passenden Regeln – Event- und Serienregeln gemeinsam – gewinnt die **höchste Priorität**
  (Standard: Last-Minute 40, Nachfrage 30, Frühbucher 20, Wochentag 10; Gleichstand: höhere
  Nachfrageschwelle, dann ältere Regel).
- Käufer sehen Bezeichnung, regulären Preis („statt …“) und eine **Erklärung** („Frühbucherpreis: gilt
  bei Buchung bis 01.12.2026 18:00“). Die angewendete Regel wird mit Basis- und Einzelpreis,
  Erklärung und Auswertungszeitpunkt in `wpcap_order_items.line_snapshot.price` gespeichert.
- Regeln wirken serverseitig über `wpcap_ticket_unit_price` (Online-Checkout, Warteliste, Kasse ohne
  Preisvorgabe; WooCommerce nur mit aktivierter Option „Pass-Captain-Preise verwenden“).

### Kampagnencodes und Stapelregeln

*Pass Captain → Kampagnen*. Höchstens drei Codes je Bestellung, angewendet nach Priorität (kleinere
Zahl zuerst) jeweils auf den Restbetrag.

- **Kombinierbar** (Standard ja): ein nicht kombinierbarer Code muss allein eingelöst werden; sonst
  Meldung „Der Code … kann nicht mit anderen Codes kombiniert werden.“
- **Auch auf Tickets mit automatischem Preis** (Standard ja): sonst ignoriert der Code Positionen, deren
  Preis aus einer Preisregel stammt.
- **Höchstrabatt je Bestellung** (optional, Betrag): deckelt den Rabatt eines Codes.
- **Maximaler Gesamtrabatt** (Einstellung `max_total_discount_bp`, nur Administratoren, auf der
  Kampagnenseite): Summe aus Preisregel-Ermäßigungen und Codes je Bestellung, bezogen auf die
  regulären Preise; spätere Codes werden zuerst gekürzt, ein Code ohne verbleibenden Rabatt wird mit
  Meldung abgelehnt.

### Käuferlimits

*Event bearbeiten → „Käuferlimits“*: höchstens N Tickets je Person für das Event, einen Termin oder
eine Ticketart, über alle Bestellungen. Person = Bestell-E-Mail (ohne Groß-/Kleinschreibung), das
angemeldete Kundenkonto (`orders.customer_user_id`, nur native Checkouts) oder beides.

- Gezählt werden bezahlte, nicht erstattete Tickets (abzüglich erstatteter Einheiten) und aktive,
  nicht abgelaufene Reservierungen offener Bestellungen.
- Atomar: Bestellungen derselben Person werden über eine Datenbanksperre (`GET_LOCK` je E-Mail bzw.
  Konto) vor Beginn der Bestell-Transaktion serialisiert; die Prüfung erfolgt in der Transaktion nach
  allen Zeilensperren. Andere Käufer werden nicht ausgebremst (Integrationstest: 8 parallele
  Bestellungen gegen ein Limit von 3 → genau 3 erfolgreich).
- Nicht begrenzt: Abendkasse (Türverkauf) und verspätete Zahlungsbestätigungen abgelaufener
  Reservierungen; Adapter können `skip_buyer_limits` setzen.

### Einwilligungsfelder für Teilnehmer

*Event → Termin → Ticketart → Teilnehmerfelder*, Typ **„Einwilligung (protokolliert)“** mit
Einwilligungstext und Version.

- Checkout: Checkbox mit Text; bei Pflichtfeldern ist die Einwilligung Voraussetzung. Bei der
  Ticketausgabe entsteht je Ticket ein Datensatz in `wpcap_consents` mit Text, Version, SHA-256-Hash,
  Quelle (`checkout`), betroffener E-Mail (Teilnehmer, sonst Käufer) und Zeitpunkt der Bestellung.
- Ändert sich der Text, nachdem Einwilligungen erteilt wurden, muss die Version geändert werden;
  bestehende Datensätze behalten ihre Fassung.
- Widerruf im **Bestellportal** (Abschnitt „Einwilligungen“): Datensatz bleibt mit `withdrawn_at` und
  Quelle erhalten, die Antwort wird gelöscht, Audit-Eintrag `consent_withdrawn`. Im Teilnehmerformular
  des Portals sind Einwilligungsfelder nicht änderbar.
- Datenschutz: eigener Exporter/Löscher („Pass Captain Einwilligungen“); Löschung mit den
  Teilnehmerantworten nach Terminende + Aufbewahrungsdauer des Felds (bzw. der globalen Einstellung).
