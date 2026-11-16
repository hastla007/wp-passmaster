# Barrierefreiheit: aktueller Stand

Die öffentliche Checkout- und Scanner-Oberfläche enthält Statusmeldungen mit `aria-live`, sichtbare Tastaturfokusse, Mindesthöhen für Schaltflächen und eine Reduced-Motion-Regel. Das ist eine Implementierungsbasis, keine WCAG-Konformitätsaussage.

**Admin-Formulare mit Fehlern (A297):** Scheitert das Speichern von „Event anlegen“, Eventdaten, Termin oder
Ticketart an einer Eingabe, nennt die Fehlermeldung das betroffene Feld; nach der Weiterleitung zeigt das
Formular die Eingaben wieder an und markiert das Feld mit `aria-invalid="true"` und Fokus (`autofocus`). Die
Eingaben liegen dafür fünf Minuten in einem Transient des Benutzers (`Forms::remember_input()`, ohne Nonce,
Referer und schlüsselartige Felder) und werden beim ersten Anzeigen genau dieses Formulars verbraucht. Nur
Formulare aus `Forms::remember_input_actions()` (Filter `wpcap_admin_remember_input_actions`) speichern
Eingaben.

Vor 1.0 sind manuelle Tastatur- und Screenreader-Prüfungen für Checkout, Scanner und WordPress-Admin nötig. Dabei müssen Fehlertexte, Fokusreihenfolge, Zoom bis 200 %, Kontrast, QR-Code-Fallback und Kamera-Berechtigungsfehler geprüft werden. Automatische Prüfungen allein reichen nicht.

## Checkout-Formular

- Je Ticket erscheint ein Block „Teilnehmer:in N · Ticketart“. Die Nummer folgt der Position im Formular und wird nach jeder Mengenänderung neu vergeben (A550).
- Wird die Menge verringert (auch beim Korrigieren einer getippten Zahl), werden überzählige Blöcke nur ausgeblendet; steigt die Menge wieder, sind die Eingaben noch da. Abgeschickt werden nur sichtbare Blöcke. „Weitere Tickets bestellen“ beginnt mit leeren Blöcken (A549).
- Der Bereich der Blöcke ist keine Live-Region. Ändert sich die Zahl der Blöcke, sagt die Mengen-Live-Region (`.wpcap-qty-live`) einen kurzen Hinweis an („Weiter unten: Angaben für 3 Teilnehmende.“) – beim Stepper zusammen mit der neuen Menge, beim Tippen nach einer kurzen Pause (A311).
- Fehlermeldungen zu Teilnehmerfeldern nennen den Block („Teilnehmer:in 2 · Erwachsene: Bitte fülle „Verein“ aus.“). Serverfehler (`wpcap_attendee_field_required`, `wpcap_attendee_field_invalid`, `wpcap_attendees_invalid`) liefern dazu in den Fehlerdaten `ticket_type_id`, `position` (n-tes Ticket dieser Art, ab 0), `index` (Position in der gesendeten Teilnehmerliste, falls gesendet) und `field_key`; das Formular markiert damit genau dieses Feld (A551).
- Käufer-E-Mail: Liegt die Domain nahe an einer verbreiteten Mail-Domain (`gmial.com` → `gmail.com`, Filter `wpcap_checkout_email_domains`) oder ist die Endung vertippt (`.con`, `.dee`), erscheint unter dem Feld „Meintest du …?“ mit einem Knopf zum Übernehmen (Live-Region, per `aria-describedby` mit dem Feld verbunden). Beim ersten Absenden mit dieser Adresse hält das Formular einmal an; wird dieselbe Adresse erneut abgeschickt, geht die Bestellung durch. Die Bestätigung sagt, was bei falscher Adresse zu tun ist (A553). Eine Selbstkorrektur der Adresse nach der Bestellung gibt es nicht.
- Saalplan: Die Liste „Deine Plätze“ wird nur neu aufgebaut, wenn sich die Auswahl ändert – nicht bei der Aktualisierung alle 30 Sekunden. Liegt der Fokus in der Liste, bleibt er beim selben Bedienelement desselben Platzes; nach „Entfernen“ springt er zum Knopf des nächsten (sonst vorherigen) Platzes, bei leerer Liste auf die Überschrift. Ein Stehplatz-Zahlenfeld wird während der Eingabe nicht überschrieben (A312).

## Kasse (Admin)

- Termin-Auswahl im Verkauf und Kassen-Filter der Schichtliste laden die Seite nicht mehr bei jeder Änderung neu (mit der Tastatur löste jede Pfeiltaste einen Seitenwechsel aus); gewählt wird mit dem Knopf „Anzeigen“ (A314).

## Kontrast und dunkle Themes

- Eventkarte, Checkout und Kalender setzen Text- und Hintergrundfarbe immer paarweise (Fehlertexte, „Ausverkauft“, Tage mit Terminen, Bankdaten). Sie hängen damit nicht von der Seitenfarbe des Themes ab; jedes Paar hat mindestens 4,5:1 (Test `zz_a318.php`). Gedämpfte Grautöne gibt es nur noch innerhalb solcher Flächen, sonst erben Hinweise die Textfarbe des Themes (A318).
- Farben sind Custom Properties (`--wpcap-error-fg`/`--wpcap-error-bg` in `public.css`, `--wpcap-cal-*` in `calendar/calendar.css`) und können vom Theme überschrieben werden. Mit der Klasse `wpcap-dark` an einem umgebenden Element (oder der Body-Klasse `is-dark-theme`, die z. B. Twenty Twenty-One setzt) gilt ein dunkler Farbsatz mit `color-scheme: dark`.
- Der Tastaturfokus im Kalender hat einen zweifarbigen Rahmen (Umriss plus Halo), der auf hellem und dunklem Grund sichtbar ist.
- Offline-Scanner (PWA): Statusmarken („offline“, OK/Warnung in der Liste) nutzen dunklere Texttöne (`--ok-text`, `--warn-text`); `--ok`/`--warn` bleiben Hintergründe hinter weißer Schrift. Alle Paare haben mindestens 4,5:1, Details im Ergebnisfeld werden nicht mehr abgeblendet; der Service-Worker-Cache wurde erhöht, damit installierte Apps das neue Stylesheet laden (A319).
