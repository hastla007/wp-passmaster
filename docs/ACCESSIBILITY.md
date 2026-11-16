# Barrierefreiheit: aktueller Stand

Die öffentliche Checkout- und Scanner-Oberfläche enthält Statusmeldungen mit `aria-live`, sichtbare Tastaturfokusse, Mindesthöhen für Schaltflächen und eine Reduced-Motion-Regel. Das ist eine Implementierungsbasis, keine WCAG-Konformitätsaussage.

Vor 1.0 sind manuelle Tastatur- und Screenreader-Prüfungen für Checkout, Scanner und WordPress-Admin nötig. Dabei müssen Fehlertexte, Fokusreihenfolge, Zoom bis 200 %, Kontrast, QR-Code-Fallback und Kamera-Berechtigungsfehler geprüft werden. Automatische Prüfungen allein reichen nicht.

## Checkout: Teilnehmerangaben

- Je Ticket erscheint ein Block „Teilnehmer:in N · Ticketart“. Die Nummer folgt der Position im Formular und wird nach jeder Mengenänderung neu vergeben (A550).
- Wird die Menge verringert (auch beim Korrigieren einer getippten Zahl), werden überzählige Blöcke nur ausgeblendet; steigt die Menge wieder, sind die Eingaben noch da. Abgeschickt werden nur sichtbare Blöcke. „Weitere Tickets bestellen“ beginnt mit leeren Blöcken (A549).
- Der Bereich der Blöcke ist keine Live-Region. Ändert sich die Zahl der Blöcke, sagt die Mengen-Live-Region (`.wpcap-qty-live`) einen kurzen Hinweis an („Weiter unten: Angaben für 3 Teilnehmende.“) – beim Stepper zusammen mit der neuen Menge, beim Tippen nach einer kurzen Pause (A311).
- Fehlermeldungen zu Teilnehmerfeldern nennen den Block („Teilnehmer:in 2 · Erwachsene: Bitte fülle „Verein“ aus.“). Serverfehler (`wpcap_attendee_field_required`, `wpcap_attendee_field_invalid`, `wpcap_attendees_invalid`) liefern dazu in den Fehlerdaten `ticket_type_id`, `position` (n-tes Ticket dieser Art, ab 0), `index` (Position in der gesendeten Teilnehmerliste, falls gesendet) und `field_key`; das Formular markiert damit genau dieses Feld (A551).
