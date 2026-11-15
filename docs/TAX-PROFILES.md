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
- REST `POST /wp-pass-captain/v1/tax-profiles` (`manage_options`) nutzt dieselben Regeln wie die Admin-Seite:
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

Rechnungshinweis: Ist im Profil ein `place_of_supply_note` hinterlegt, wird er auf der Rechnung
gedruckt (`buyer_snapshot.tax_notes`, `Invoices::seller_notes`). Ohne Text wird nur dann
„Leistungsort: Veranstaltungsort (XX).“ gedruckt, wenn das Eventland vom Verkäuferland abweicht.

## Offene fachliche Punkte

- Maßgeblicher Zeitpunkt bei Satzänderungen (Bestellung/Zahlung = Anzahlung vs. Veranstaltungstag);
  umgesetzt: Bestelldatum, per Filter änderbar.
- Registrierungspflichten/OSS bei Events im Ausland, B2B-Fälle und Reverse Charge je Land.
- Gemischte Steuersätze (z. B. Ticket mit Verpflegungsanteil) sind nur über getrennte Ticketarten
  abbildbar.
- Formulierung des Leistungsort-Hinweises je Land.
