# Serientermine: Bearbeiten und Änderungsverläufe

Modul „Serientermine“ (Pass Captain → Serientermine). Grundfunktionen: Wiederholungsregel (täglich,
wöchentlich an Wochentagen, monatlich am Tag X oder am n-ten Wochentag, Datumsliste) mit Ausnahmen,
Vorschau, idempotentes Anlegen, Ticketarten aus einem Vorlagentermin, Terminkontingent,
Verkaufsfenster relativ zum Beginn, Saalplan-Verknüpfung, Preisregeln (Frühbucher, Last-Minute,
Nachfrage, Wochentag/Zeitfenster) und gemeinsame Kontingente über alle Termine der Serie.
Sommer-/Winterzeit: Die Uhrzeit bleibt Ortszeit; Zeiten in der ausgelassenen Stunde werden
verschoben, doppelte Zeiten nehmen die erste (Sommerzeit) – beides wird in jeder Vorschau markiert.

## Serie bearbeiten (A128)

Unter „Gespeicherte Serien“ → **Serie bearbeiten** (oder im Serienbereich „Seriendefinition
bearbeiten“) ist das Formular mit der gespeicherten Definition vorbefüllt. Änderbar sind Regel
(Wiederholung, Wochentage, Intervall, Beginn/Ende/Anzahl, Ausnahmen, Uhrzeit, Dauer, Zeitzone),
Bezeichnung, Vorlagentermin, Ort, Terminkontingent, Verkaufsfenster und Saalplan-Version.

**Auswirkungen prüfen** zeigt vor dem Speichern:

- die Änderungen der Definition (alt → neu),
- je betroffenem Termin die Aktion: *neu*, *geändert* (Feld: alt → neu), *gelöscht*,
  *bleibt bestehen und wird markiert*, *Markierung aufgehoben*, *übersprungen* oder *Konflikt*,
- Hinweise zu Zeitumstellung, Terminverschiebungen mit Ticketinhabern und Verkaufsfenstern.

**Änderungen übernehmen** wendet genau diese Vorschau an. Hat sich zwischenzeitlich etwas geändert
(neue Verkäufe, anderer Termin), wird nichts gespeichert und die Vorschau muss erneut geprüft werden.

Regeln:

- Nur **künftige** Termine werden angefasst. Vergangene bleiben unverändert.
- **Ausverkaufte** und **abgesagte** Termine bleiben unverändert, außer „Ausverkaufte Termine
  einbeziehen“ bzw. „Abgesagte Termine einbeziehen“ ist angehakt (abgesagte bleiben abgesagt).
- Bestehende Termine werden über den Tag zugeordnet, für den sie erzeugt wurden; ein von Hand
  verschobener Termin behält so seinen Platz in der Serie.
- Übertragen werden nur Werte, die sich **in der Definition** geändert haben (z. B. neues
  Kontingent oder neue Uhrzeit). Andere Einzeländerungen an einem Termin bleiben erhalten.
- Ein Kontingent unter den bereits verkauften Tickets ist ein Konflikt; der Termin bleibt unverändert.
- Fällt ein Termin aus der Regel, wird er nur gelöscht, wenn nichts auf ihn oder seine Ticketarten
  verweist (Bestellungen, Tickets, Reservierungen, Sitzplätze, Wartelisten, Gästelisten, Pässe …).
  Sonst **bleibt er bestehen und wird markiert** („außerhalb der Serienregel“). Markierte Termine
  stehen oben im Serienbereich mit Link auf „Termin absagen“ (normaler Absageablauf mit Erstattung
  und Benachrichtigung). Kommt der Tag wieder in die Regel, wird die Markierung aufgehoben.
- Wird der Beginn eines Termins mit ausgestellten Tickets verschoben, feuert
  `wpcap_occurrence_rescheduled` (Benachrichtigung der Ticketinhaber wie bei einer Einzeländerung).
  Ein größeres Kontingent löst `wpcap_capacity_increased` aus (Warteliste).
- Verkaufsfenster: Ein in der Definition gesetzter Abstand („Tage/Minuten vor Beginn“) wird neu
  berechnet; ohne festen Abstand wird ein vorhandenes Fenster mit dem Beginn mitverschoben.
- Saalplan: Neue Version → künftige Termine werden umgehängt (Bereichszuordnung des Termins bzw.
  der Vorlage nach Ticketart-Name). Verkaufte Plätze bleiben geschützt; scheitert die Umstellung
  für einen Termin, erscheint ein Hinweis.
- Am Ende feuert `wpcap_series_updated` (siehe [HOOKS.md](HOOKS.md)).

Preisregeln lassen sich in der Regelliste über **Bearbeiten** ändern (die Regel-ID bleibt, die
Änderung gilt sofort für alle Termine der Serie).

## Änderungsverläufe (A114)

- **Termin** (Events → Termin): Änderungen an Beginn, Ende, Einlass, Zeitzone, Ort, Kontingent und
  Status sowie an den Ticketarten (Preis, Steuersatz, Kontingent, Höchstmenge, Verkaufsfenster,
  Status) – jeweils „Feld: alt → neu“, mit Zeitpunkt, Benutzer und Herkunft (z. B. „durch Serie #3“),
  außerdem Absage mit Grund, Saalplan-Verknüpfung und Markierung durch eine Serienänderung.
- **Serie** (Serientermine → Serie): Definitionsänderungen alt → neu mit Auswirkung (hinzugefügt,
  geändert, entfernt, markiert, übersprungen), erzeugte Termine, Preisregeln (angelegt mit allen
  Werten, geändert alt → neu, entfernt) und gemeinsame Kontingente.

Die Verläufe werden aus dem Audit-Log gelesen (`details.changes` = Feld → [alt, neu], Rohwerte; die
Anzeige formatiert Zeiten in der Zeitzone des Termins bzw. der Serie). Sie folgen der
Aufbewahrungsfrist des Audit-Logs (Standard 730 Tage, `wpcap_privacy_retention_days`).
Serienbezogene Einträge (`series_*`) haben ab Schema 3.8.7 den Objekttyp `series`
(vorher `event` mit `series_id` in den Details).

Schema 3.8.7: `wpcap_series_occurrences.flag` / `flagged_at` (Markierung „außerhalb der Serienregel“).
