# Verkaufsmodelle: Eigenhändler und Vermittler

WP Pass Captain unterstützt je Veranstalter eines von zwei Verkaufsmodellen. Das Modell entscheidet,
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
| Rechnungskorrekturen | Serie der Plattform (`RK-…`, Bestandsinstallationen weiter `GS-…`) | Serie des Veranstalters (`{Präfix}-K-…`) |
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
| Kleinunternehmer + Hinweistext | keine USt auf Veranstalter-Belegen; Standardtext nach Land: DE § 19 UStG, AT § 6 Abs. 1 Z 27 UStG; eigener Text hat Vorrang |

Plattformdaten (Einstellungen → Rechnungssteller): Name, Anschrift, USt-IdNr., **Steuernummer**,
**Land** (Standard DE) und **Kleinunternehmer** mit optionalem Hinweistext. Ist die Plattform selbst
Kleinunternehmer, tragen ihre Rechnungen (Eigenhändler-Modell und Plattform-Events) den Hinweis
(leer = Standardtext für das Land), und eine Rechnung wird verweigert, solange die Bestellung
Umsatzsteuer enthält (`wpcap_invoice_small_business_vat`, § 14c UStG) – Ticketarten dann mit 0 % anlegen.

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
- Rechnungskorrekturen schlüsseln Netto/USt/Brutto je Steuersatz auf: Der Korrekturbetrag und die
  USt werden auf die Bestellpositionen der Erstattung verteilt (erstattete Tickets mit dem bezahlten
  Positionsbrutto nach Rabatt, reine Betragserstattungen über alle Positionen; Restcent-Verteilung
  nach größtem Rest) und mit dem Beleg gespeichert (`wpcap_credit_notes.rates`). Belege von vor
  Schema 3.8.3 werden bei der Anzeige identisch berechnet. Der Buchhaltungsexport enthält je
  Rechnungskorrektur eine Zeile je Steuersatz (Belegart `Rechnungskorrektur`).
- Der Buchhaltungsexport (Spalten und Zeilenarten: [OPERATIONS.md](OPERATIONS.md#buchhaltungsexport)) enthält die Spalten `Verkaufsmodell` und `Rechnungssteller`. Belege im Modell
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
| `credit` | `RK-2026-000001` | Rechnungskorrektur der Plattform (Präfix einstellbar; vor Schema 3.8.3 Standard `GS`) |
| `invoice-org-{ID}` | `TAG-2026-000001` / `V7-2026-000001` | Kundenrechnung im Namen des Veranstalters |
| `credit-org-{ID}` | `TAG-K-2026-000001` | Rechnungskorrektur im Namen des Veranstalters |
| `partner` | `PA-2026-000001` | Provisionsrechnung / Rechnung über Plattformgebühren |
| `credit-self` | `GU-2026-000001` | Gutschrift (Gutschriftsverfahren) |

Die Spalte `series` der Tabelle `wpcap_document_sequences` wurde auf 40 Zeichen erweitert (Schema 3.5.0).

## Länder-Steuerprofile (Steuerprüfung)

Seit Schema 3.9.2 (A115) kann der Umsatzsteuersatz eines Tickets aus einem **Steuerprofil** je Land
und Steuerklasse kommen statt aus dem Feld „Steuersatz“ der Ticketart.

- **Leistungsort:** Für Eintrittsberechtigungen ist das Land der Veranstaltung maßgeblich
  (Art. 53/54 MwStSystRL, § 3a Abs. 3 Nr. 5 UStG). Jeder Termin hat dafür das Feld
  **„Veranstaltungsland“** (ISO-Code); leer = Land des Rechnungsstellers (Einstellungen →
  Rechnungssteller → Land).
- **Auflösung je Bestellposition:** Hat die Ticketart eine **Steuerklasse** (z. B. `culture`) und gibt
  es für (Veranstaltungsland, Steuerklasse) ein **aktives** Profil, dessen Gültigkeit
  (`gültig ab`/`gültig bis`, einschließlich, Datum in der Zeitzone der Website) den Verkaufstag
  enthält, gilt dessen Satz. Sonst gilt wie bisher der Satz der Ticketart – bestehende Installationen
  ändern sich also erst, wenn Ticketarten eine Steuerklasse bekommen und ein Profil aktiviert wird.
- **Nachvollziehbarkeit:** Die Bestellposition speichert neben `tax_rate_bp` die Quelle
  (`tax_source`: `profile`, `ticket_type` oder `external` für vom Kanal vorgegebene Steuer), Profil-ID,
  Profilversion und Land. Die Bestellansicht zeigt das unter dem Steuersatz. Rechnungen,
  Rechnungskorrekturen (Aufstellung je Steuersatz), Buchhaltungsexport, Berichte, Z-Bericht und
  Veranstalterabrechnungen lesen ausschließlich den gespeicherten Satz der Position: Ein späteres
  Außerkraftsetzen oder eine neue Profilversion ändert verkaufte Bestellungen nicht.
- **Steuerprüfung (Menü Finanzen & Auswertung → Steuerprüfung):** Profile entstehen als **Entwurf**
  (Admin-Seite oder `POST /wp-pass-captain/v1/tax-profiles`). Aktivieren kann nur, wer Finanzrechte
  hat (`wpcap_view_finance`, z. B. Rolle „Finanzbuchhaltung“, oder Administration), nach Bestätigung
  der Prüfung und mit optionalem Prüfvermerk. **Vier-Augen-Prinzip** (Einstellung
  „Vier-Augen-Prinzip für Steuerprofile“, Standard an): Wer den Entwurf angelegt oder zuletzt
  bearbeitet hat, darf ihn nicht selbst aktivieren; Administratoren sind ausgenommen (wie bei der
  Gästeliste). Ein zweites aktives Profil für dasselbe Land und dieselbe Steuerklasse mit
  überlappendem Zeitraum wird abgelehnt. Aktive Profile werden nicht bearbeitet, sondern außer Kraft
  gesetzt und durch eine neue Version ersetzt. Anlegen, Bearbeiten, Aktivieren, abgelehnte Prüfungen
  und Außerkraftsetzen stehen im Audit-Log (`tax_profile_*`).
- **Geltungsbereich:** eigener Checkout, Abendkasse (auch Katalog), Partner-API (auch Netto-Preislisten
  und Katalog), Wartelisten-Angebote, Ticketumtausch und Gutscheineinlösungen.
  **WooCommerce-Bestellungen** verwenden die Steuereinstellungen von WooCommerce; die Profile gelten
  dort nicht (Position mit dem Satz der Ticketart). Andere Kanäle können über den Filter
  `wpcap_tax_profiles_apply` ausgenommen werden (docs/HOOKS.md).
- Die Seite zeigt außerdem, welche Steuerklassen kommende Termine verwenden und ob heute ein Profil
  greift – ohne Profil gilt der Satz der Ticketart.

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
6. **Steuersätze der Ticketarten** passend zum Modell und Veranstalter pflegen (Kleinunternehmer: 0 %);
   bei Steuerprofilen Satzänderungen rechtzeitig als neue Version anlegen und prüfen lassen.
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
- Erledigt (Schema 3.8.3): Rechnungskorrekturen der Plattform tragen für neue Installationen das
  Präfix `RK` und heißen im Buchhaltungsexport, in Berichten und in der Erstattungsmail
  „Rechnungskorrektur“ (nicht „Gutschrift“, Verwechslung mit § 14 Abs. 2 Satz 2 UStG). Bestehende
  Installationen behalten ihr bisheriges Präfix (ohne gespeicherte Einstellung wird `GS` fest
  eingetragen); ausgestellte Belege werden nicht umnummeriert. Rechnungskorrekturen weisen Netto, USt
  und Brutto je Steuersatz aus (Druckansicht, PDF, Buchhaltungsexport mit einer Zeile je Satz).
- Negative Monatswerte (mehr Erstattungen als Verkäufe): Gutschrift mit negativem Betrag oder
  Korrekturbeleg zu einer früheren Gutschrift?
- Kleinunternehmer im Eigenhändler-Modell: Wer trägt die USt auf den Kundenumsatz wirtschaftlich
  (umgesetzt: Gutschrift = Netto-Umsatz ohne USt)?
- Österreich: Formulierung der Hinweise (Kleinunternehmer § 6 Abs. 1 Z 27 UStG, Reverse Charge
  § 19 Abs. 1 UStG) und Anforderungen an Gutschriften nach § 11 Abs. 7, 8 UStG.
