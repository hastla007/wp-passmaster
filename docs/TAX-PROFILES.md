# Steuerprofile (versionierte Länder-/Steuerklassen-Profile)

> **Fachlich zu prüfen:** Pass Captain liefert **keine** Steuerregeln aus. Steuerprofile sind technische
> Vorlagen, die der Betreiber anlegt. Steuersätze, Steuerklassen, Leistungsort, Gültigkeitszeiträume
> und Rechnungshinweise müssen vor der Aktivierung von einer Steuerberatung geprüft werden. Die
> Admin-Oberfläche kennzeichnet jedes Profil entsprechend.

Code: `src/Sales/TaxProfiles.php` (Logik), `src/Sales/TaxProfilesPage.php` (Admin „Pass Captain →
Steuerprofile“, Recht `manage_options`), Tabelle `wpcap_tax_profiles`.

## Modell

Ein Profil ist **Land + Steuerklasse**, z. B. `AT` + `standard` oder `DE` + `culture`. Jede Zeile ist
eine **Version** mit:

| Feld | Bedeutung |
|---|---|
| `country_code` | ISO-Ländercode (zweistellig) |
| `tax_class` | technischer Name, z. B. `standard`, `reduced`, `culture`; Ticketarten wählen ihre Klasse |
| `rate_basis_points` | Steuersatz in Basispunkten (2000 = 20 %) |
| `version` | eindeutig je Land/Klasse, z. B. `2027-01` |
| `effective_from` / `effective_until` | Gültigkeit (Datum, inklusive; `until` leer = offen) |
| `status` | `draft` → `active` → `archived` |
| `label`, `place_of_supply_note` | Bezeichnung; optionaler Rechnungshinweis (geprüfter Text) |

Regeln:

- Neue Versionen entstehen als **Entwurf** und werden erst nach dem Aktivieren angewendet.
- **Aktive und archivierte Versionen sind unveränderlich.** Für einen neuen Satz wird eine neue
  Version mit eigenem „gültig ab“ angelegt.
- Aktive Versionen desselben Landes und derselben Klasse dürfen sich zeitlich **nicht überschneiden**
  (die Aktivierung wird sonst abgelehnt). Für einen Satzwechsel: alte Version archivieren oder von
  vornherein mit „gültig bis“ anlegen, neue Version ab dem Stichtag aktivieren.
- REST `POST /pass-captain/v1/tax-profiles` (`manage_options`) nutzt dieselben Regeln wie die Admin-Seite:
  Felder `country_code`, `tax_class`, `rate_basis_points` oder `rate` (Prozent), `version`, `effective_from`,
  `effective_until`, `label`, `place_of_supply_note`. Ohne `tax_profile_id` entsteht ein Entwurf (201), mit
  `tax_profile_id` wird ein Entwurf geändert (200; aktive/archivierte Versionen: `wpcap_tax_profile_frozen`).
  `activate: true` aktiviert ihn danach; überschneidet er sich mit einer aktiven Version, bleibt der Entwurf
  gespeichert und die Antwort ist `wpcap_tax_profile_overlap` (409) mit `tax_profile_id`.

## Auswahl je Bestellposition

`Orders::create()` ermittelt je Position (außer bei vertrauenswürdigen Adaptern, die die Positionssteuer
selbst übergeben, z. B. WooCommerce):

1. **Land** = Steuerland des Events (`wpcap_events.tax_country`, Event bearbeiten → „Steuerland
   (Leistungsort)“). Eintrittsberechtigungen zu Veranstaltungen werden in der Regel am
   Veranstaltungsort besteuert; ohne Angabe gilt das Verkäuferland (Einstellung `seller_country`).
2. **Klasse** = Steuerklasse der Ticketart (`wpcap_ticket_types.tax_class`, leer = `standard`).
3. **Datum** = Leistungszeitpunkt: das **lokale Datum des Terminbeginns** (`occurrence.starts_at`,
   umgerechnet in die Zeitzone des Termins, sonst die Website-Zeitzone). Maßgeblich ist in der Regel
   der Satz, der bei Ausführung der Leistung gilt (DE § 27 Abs. 1 UStG, AT § 28 UStG) – ein Satzwechsel
   zwischen Kauf und Veranstaltung wirkt also auf bereits geplante Termine. Ein Termin am 1.1. um
   00:30 Uhr Wiener Zeit (31.12. 23:30 UTC) gilt als Leistung am 1.1. Ohne Terminbeginn gilt das
   lokale Bestelldatum. Filter `wpcap_tax_profile_date`
   (`string $date, object $type, object $occurrence, string $order_date`): `$order_date` ist das
   lokale Bestelldatum, z. B. um (nach steuerlicher Prüfung) auf das Bestelldatum zurückzustellen.
   Der Stichtag steht im Positions-Snapshot (`line_snapshot.tax.date`). Bis Version 3.17.3 war der
   Stichtag das Bestelldatum (UTC); bestehende Bestellungen behalten ihren eingefrorenen Satz.
4. Die aktive Version, deren Gültigkeit das Datum enthält, liefert den Satz. **Ohne passende aktive
   Version** gilt wie bisher der Steuersatz der Ticketart (`tax_rate_bp`).

## Snapshot und Belege

Die angewendete Version wird in der Bestellposition eingefroren:

- `wpcap_order_items.tax_rate_bp` und `tax_minor` (Satz und Steuerbetrag der Position),
- `wpcap_order_items.tax_profile_id`,
- `wpcap_order_items.line_snapshot` (JSON, Schlüssel `tax`): `profile_id`, `country`, `tax_class`,
  `version`, `label`, `rate_bp`, `effective_from`, `effective_until`, `place_of_supply`
  (`event_country`/`seller_country`), `note`, `date`.

Rechnungen (`Invoices::issue_for_order`), Rechnungskorrekturen (`Invoices::credit_note_for_refund`),
Erstattungen (`Refunds`) und Berichte verwenden ausschließlich diese Positionswerte. Ein späterer
Satzwechsel (neue Version) wirkt nur auf neue Bestellungen; eine Erstattung einer älteren Bestellung
verwendet den ursprünglichen Satz (Integrationstest „refund after the rate change …“).

### Rechnungskorrekturen je Steuersatz

Seit DB-Version 3.17.4 schreibt jede Rechnungskorrektur (`wpcap_credit_notes`) zusätzlich je
Steuersatz eine Zeile in `wpcap_credit_note_lines` (`credit_note_id`, `tax_rate_bp`, `net_minor`,
`tax_minor`, `total_minor`; `Invoices::credit_note_split()`): Bei Ticket-Erstattungen nach den
erstatteten Positionen, bei reinen Betragserstattungen (z. B. Dashboard-Erstattung, Chargeback)
anteilig nach den (rabattierten) Positionswerten je Satz. Die Zeilen ergeben immer genau die Summen
des Korrekturbelegs. Druckansicht, PDF und Belegexport zeigen die Korrektur je Satz (mit
Satzspalte). **Bestandsgutschriften** von vor 3.17.4 werden nicht nachträglich umgerechnet und weiter
einzeilig (ohne Satz) dargestellt.

### Buchhaltungsexport

*Berichte → „Buchhaltung: Belege“* (`admin-post.php?action=wpcap_export_accounting&mode=invoices`)
exportiert Rechnungszeilen und Rechnungskorrekturen (je Satz) mit der Spalte **Steuerland**.
*„Buchhaltung: Zahlungen & Erstattungen“* (`mode=ledger`, `Invoices::ledger_export_rows()`) ist
unabhängig von Rechnungen (auch bei `auto_invoice` aus): je Bestellposition eine Zeile „Zahlung“
(Datum der Zahlungsbestätigung laut Protokoll, sonst Bestelldatum) und je abgeschlossener
Erstattung bzw. Rückbuchung („Erstattung“/„Chargeback“) eine Zeile je Steuersatz, jeweils mit
Belegnummer (Rechnung/Korrektur, falls vorhanden), Bestell- und Erstattungs-ID, Zahlungsreferenz und
Steuerland (aus `line_snapshot.tax.country`, sonst Steuerland des Events, sonst Verkäuferland).
Beide Exporte werden gestreamt (`Reports\Scope::send_csv`, Formelschutz je Zelle) und laufen durch
`wp_pass_captain_export_rows_v1` (Typ `accounting` bzw. `accounting_ledger`). Der Export ersetzt
keine Prüfung durch die Steuerberatung.

Rechnungshinweis: Ist im Profil ein `place_of_supply_note` hinterlegt, wird er auf der Rechnung
gedruckt (`buyer_snapshot.tax_notes`, `Invoices::seller_notes`). Ohne Text wird nur dann
„Leistungsort: Veranstaltungsort (XX).“ gedruckt, wenn das Eventland vom Verkäuferland abweicht.

## Auswertungen für die Steuerberatung: Länder/Sätze (OSS) und Zusammenfassende Meldung

Auf der Seite „Berichte“ stehen für Personen mit Finanzrechten (`wpcap_view_finance`) zwei weitere
CSV-Exporte für den gewählten Zeitraum (`Reports\TaxSummary`, Exporttypen `tax_oss` und `tax_zm`; nur
für die ganze Website, eine auf Events beschränkte Berichtssicht erhält nur die Kopfzeile):

- **Steuerauswertung nach Land und Satz (OSS):** die Zeilen des Buchungsexports (Zahlungen,
  Erstattungen, Chargebacks, fehlgeschlagene und zurückgebuchte Erstattungen) summiert je Steuerland,
  USt-Satz und Währung (Netto, USt, Brutto, Anzahl Buchungen). Zeilen ohne Steuerland zählen zum
  Verkäuferland. Spalte `OSS-Kandidat`: `ja` für ein anderes EU-Land als das Verkäuferland, `nein` für
  das Verkäuferland und Länder außerhalb der EU, `prüfen` für Erstattungen gemischter Bestellungen mit
  mehreren Ländern.
- **Zusammenfassende Meldung (Reverse Charge):** abgeschlossene und bezahlte Veranstalter-Abrechnungen,
  deren Provisions-/Gebührenrechnung im Reverse-Charge-Verfahren ausgestellt wurde, summiert je
  USt-IdNr. und Land des Veranstalters (aus dem Schnappschuss beim Abschluss) über die
  Abrechnungsmonate des Zeitraums; Art „Sonstige Leistung“, mit Belegnummern und Monaten.

Beide Auswertungen rechnen nichts neu, sie fassen die gespeicherten Belege zusammen. Ob und für welchen
Zeitraum eine OSS-Erklärung oder ZM abzugeben ist (Registrierung, Schwellen, Meldezeitraum), prüft die
Steuerberatung. Spalten lassen sich über `wp_pass_captain_export_rows_v1` (Typ `tax_oss` bzw. `tax_zm`)
ergänzen.

## Offene fachliche Punkte

- Maßgeblicher Zeitpunkt bei Satzänderungen und Anzahlungen (Mindest-Istversteuerung bei
  Vorauszahlung); umgesetzt: Leistungszeitpunkt (lokaler Veranstaltungstag), per Filter änderbar.
- Registrierungspflichten/OSS bei Events im Ausland, B2B-Fälle und Reverse Charge je Land.
- Gemischte Steuersätze (z. B. Ticket mit Verpflegungsanteil) sind nur über getrennte Ticketarten
  abbildbar.
- Formulierung des Leistungsort-Hinweises je Land.
