# Barrierefreiheit: aktueller Stand

Die öffentliche Checkout- und Scanner-Oberfläche enthält Statusmeldungen mit `aria-live`, sichtbare Tastaturfokusse, Mindesthöhen für Schaltflächen und eine Reduced-Motion-Regel. Das ist eine Implementierungsbasis, keine WCAG-Konformitätsaussage.

**Admin-Formulare mit Fehlern (A297):** Scheitert das Speichern von „Event anlegen“, Eventdaten, Termin oder
Ticketart an einer Eingabe, nennt die Fehlermeldung das betroffene Feld; nach der Weiterleitung zeigt das
Formular die Eingaben wieder an und markiert das Feld mit `aria-invalid="true"` und Fokus (`autofocus`). Die
Eingaben liegen dafür fünf Minuten in einem Transient des Benutzers (`Forms::remember_input()`, ohne Nonce,
Referer und schlüsselartige Felder) und werden beim ersten Anzeigen genau dieses Formulars verbraucht. Nur
Formulare aus `Forms::remember_input_actions()` (Filter `wpcap_admin_remember_input_actions`) speichern
Eingaben.

**Sprache eigenständiger Seiten (A320):** Druck- und Belegseiten (Teilnehmer-, No-Show- und Gästeliste,
Kassenbeleg, Storno-/Auszahlungsbeleg, Z-Bericht, Abrechnungsblatt, Partnerabrechnung) sowie Ticketseite,
Bestellportal, Wartelisten-Angebot und Rechnungsansicht setzen `lang` auf die Sprache, in der sie gerade
gerendert werden (`determine_locale()` als BCP 47, z. B. `en-US`; im Admin die Benutzersprache), und
`dir="rtl"` bei rechts-nach-links-Sprachen (`Frontend\Format::html_lang_attributes()`). Screenreader lesen
übersetzte Listen und Belege damit in der richtigen Sprache vor.

Vor 1.0 sind manuelle Tastatur- und Screenreader-Prüfungen für Checkout, Scanner und WordPress-Admin nötig. Dabei müssen Fehlertexte, Fokusreihenfolge, Zoom bis 200 %, Kontrast, QR-Code-Fallback und Kamera-Berechtigungsfehler geprüft werden. Automatische Prüfungen allein reichen nicht.

## Automatische Prüfung (Release-Gate)

`tests/a11y/gate.sh` prüft öffentliche Seiten und jede Admin-Seite des Plugins mit axe-core (WCAG 2.2 A/AA + best-practice) und geht Checkout und Scanner mit der Tastatur durch; jeder Verstoß bricht ab (Details: `docs/TESTING.md`). Admin-Hinweise für eigene Erweiterungen: Selects und Felder ohne sichtbares `<label>` bekommen einen Namen über `Forms::aria_label()`; Checkboxen/Radios auf Plugin-Seiten sind 24 × 24 px groß (WCAG 2.5.8), Statusfarben erfüllen 4,5:1. Der Kamera-Berechtigungsfehler des Web-Scanners und die manuelle Code-Eingabe sind per E2E-Test (`tests/e2e/scanner.test.js`) abgedeckt; Screenreader-Tests bleiben manuell.
## Checkout-Formular

- Je Ticket erscheint ein Block „Teilnehmer:in N · Ticketart“. Die Nummer folgt der Position im Formular und wird nach jeder Mengenänderung neu vergeben (A550).
- Wird die Menge verringert (auch beim Korrigieren einer getippten Zahl), werden überzählige Blöcke nur ausgeblendet; steigt die Menge wieder, sind die Eingaben noch da. Abgeschickt werden nur sichtbare Blöcke. „Weitere Tickets bestellen“ beginnt mit leeren Blöcken (A549).
- Der Bereich der Blöcke ist keine Live-Region. Ändert sich die Zahl der Blöcke, sagt die Mengen-Live-Region (`.wpcap-qty-live`) einen kurzen Hinweis an („Weiter unten: Angaben für 3 Teilnehmende.“) – beim Stepper zusammen mit der neuen Menge, beim Tippen nach einer kurzen Pause (A311).
- Fehlermeldungen zu Teilnehmerfeldern nennen den Block („Teilnehmer:in 2 · Erwachsene: Bitte fülle „Verein“ aus.“). Serverfehler (`wpcap_attendee_field_required`, `wpcap_attendee_field_invalid`, `wpcap_attendees_invalid`) liefern dazu in den Fehlerdaten `ticket_type_id`, `position` (n-tes Ticket dieser Art, ab 0), `index` (Position in der gesendeten Teilnehmerliste, falls gesendet) und `field_key`; das Formular markiert damit genau dieses Feld (A551).
- Summe am Bestellknopf (A602): Neben dem Bestellknopf steht die vorläufige Summe (`.wpcap-submit-total` in `.wpcap-submit-bar`, nur visuell – angesagt wird weiterhin die Live-Region `.wpcap-estimate` oben). Auf schmalen Bildschirmen (bis 600 px) bleiben Summe und Knopf am unteren Bildschirmrand stehen, solange das Formular sichtbar ist (eigenes Farbpaar `--wpcap-bar-fg`/`--wpcap-bar-bg`, Abstand zum Home-Indikator). Einzeilige Straßenfelder (Eventkarte, Bestellportal, Admin, Terminbuchung) nutzen `autocomplete="address-line1"` statt des mehrzeiligen `street-address`.
- Browser-Autofill (A603): Das Namensfeld jedes Teilnehmerblocks trägt `autocomplete="section-att{n} name"` (n = Position, wird beim Umnummerieren angepasst). Jeder Block ist damit ein eigener Autofill-Abschnitt – der Browser trägt den Namen der Käuferin nicht mehr gleichzeitig in alle Blöcke ein, bietet ihn aber je Block weiter an. Das E-Mail-Feld der Teilnehmenden bleibt `autocomplete="off"`.
- Teilnehmer-E-Mail (A599): Eine eingegebene, aber ungültige Adresse lehnt auch der Server ab (`wpcap_attendee_field_invalid`, HTTP 400, `field: attendees` mit `ticket_type_id`, `position`, `index` und `field_key: "__email"`, `reason: email_invalid`) – vorher wurde sie still verworfen und das Ticket ging nur an die Käuferadresse. Das Formular markiert das E-Mail-Feld des betroffenen Blocks. Ein leeres Feld bleibt optional.
- Käufer-E-Mail: Liegt die Domain nahe an einer verbreiteten Mail-Domain (`gmial.com` → `gmail.com`, Filter `wpcap_checkout_email_domains`) oder ist die Endung vertippt (`.con`, `.dee`), erscheint unter dem Feld „Meintest du …?“ mit einem Knopf zum Übernehmen (Live-Region, per `aria-describedby` mit dem Feld verbunden). Beim ersten Absenden mit dieser Adresse hält das Formular einmal an; wird dieselbe Adresse erneut abgeschickt, geht die Bestellung durch. Die Bestätigung sagt, was bei falscher Adresse zu tun ist (A553). Eine Selbstkorrektur der Adresse nach der Bestellung gibt es nicht.
- Saalplan: Die Liste „Deine Plätze“ wird nur neu aufgebaut, wenn sich die Auswahl ändert – nicht bei der Aktualisierung alle 30 Sekunden. Liegt der Fokus in der Liste, bleibt er beim selben Bedienelement desselben Platzes; nach „Entfernen“ springt er zum Knopf des nächsten (sonst vorherigen) Platzes, bei leerer Liste auf die Überschrift. Ein Stehplatz-Zahlenfeld wird während der Eingabe nicht überschrieben (A312).
- Saalplan auf dem Smartphone (A090): Jede Kategorie hat neben ihrer Farbe eine eigene **Sitzform** (Kreis, Quadrat, Raute, Dreieck, danach wieder von vorn); die Legende zeigt dieselben Formen und die Zustandszeichen im Sitz – ✓ ausgewählt, × belegt, ♿ rollstuhlgerecht, + Begleitplatz –, Farbe ist nie die einzige Unterscheidung. **Sitzgröße:** Sitze sind mindestens 24 px groß und ebenso weit auseinander (WCAG 2.5.8); „Große Sitze“ schaltet auf 44 px (2.5.5), auf Touch-Geräten (`pointer: coarse`) ist das der Start. **Zoom/Pan:** Knöpfe −/+ (100–300 %), „Ganzer Saal“ setzt zurück; mit zwei Fingern zoomen (um den Punkt zwischen den Fingern), mit einem Finger verschieben (natives Scrollen), am Rechner Strg/⌘ + Mausrad und Ziehen mit der Maus. Eine Geste wählt keinen Platz. **Liste:** „Saalplan | Liste“ schaltet zwischen zwei gleichwertigen Ansichten (echte Kontrollkästchen je Reihe, mit Kategorie bei mehreren Kategorien); auf schmalen Bildschirmen (bis 600 px) mit mehr als 40 Sitzen startet die Liste, der Fokus springt beim Umschalten in die gezeigte Ansicht. Tests: `tests/js/seating-picker-a090.test.js`.

## Kalender

- Der zugängliche Name jedes Kalender-Links beginnt mit seinem sichtbaren Text (WCAG 2.5.3, A393): Monatseintrag „20:00 Konzert“ heißt „20:00 Konzert, 16.11.2026“, der Listen-Link „Zum Kalender (.ics)“ heißt „Zum Kalender (.ics): Konzert am 16.11.2026“. Sprachsteuerung („Klicke 20:00 Konzert“) trifft damit den Link.
- Monatskalender auf schmalen Bildschirmen (bis 600 px, A394): Statt einer Tabelle, deren Zellen per CSS zu Blöcken werden (Tabellensemantik weg, Spaltenköpfe ausgeblendet, kein Monat am Tag), zeigt der Kalender eine echte Liste (`ol.wpcap-cal-agenda-days`) unter dem Monatstitel: je Tag mit Terminen „Montag, 16. November“ und die Termine. Auf breiten Bildschirmen bleibt die Tabelle mit Beschriftung und Spaltenköpfen; die jeweils andere Darstellung ist mit `display:none` auch für Screenreader ausgeblendet.

## Bestellportal und Warteliste

- Fokus nach asynchronen Aktionen (A395): Entfernt eine Aktion nach Erfolg ihren Knopf (Ticket übertragen, Erstattung anfragen im Bestellportal) oder sperrt sie das Formular (Eintragen in die Warteliste auf der Eventkarte), bekommt die Meldung `tabindex="-1"` und den Fokus – Screenreader lesen das Ergebnis vor, die Tab-Reihenfolge beginnt dort statt am Seitenanfang. Knöpfe, die während der Anfrage gesperrt waren (erneut senden, Bankverbindung, Rechnungsdaten, fehlgeschlagene Übertragung/Warteliste), bekommen den Fokus zurück, wenn er auf `<body>` gefallen ist.

## Kasse (Admin)

- Termin-Auswahl im Verkauf und Kassen-Filter der Schichtliste laden die Seite nicht mehr bei jeder Änderung neu (mit der Tastatur löste jede Pfeiltaste einen Seitenwechsel aus); gewählt wird mit dem Knopf „Anzeigen“ (A314).

## Kontrast und dunkle Themes

- Eventkarte, Checkout und Kalender setzen Text- und Hintergrundfarbe immer paarweise (Fehlertexte, „Ausverkauft“, Tage mit Terminen, Bankdaten). Sie hängen damit nicht von der Seitenfarbe des Themes ab; jedes Paar hat mindestens 4,5:1 (Test `zz_a318.php`). Gedämpfte Grautöne gibt es nur noch innerhalb solcher Flächen, sonst erben Hinweise die Textfarbe des Themes (A318).
- Farben sind Custom Properties (`--wpcap-error-fg`/`--wpcap-error-bg` in `public.css`, `--wpcap-cal-*` in `calendar/calendar.css`) und können vom Theme überschrieben werden. Mit der Klasse `wpcap-dark` an einem umgebenden Element (oder der Body-Klasse `is-dark-theme`, die z. B. Twenty Twenty-One setzt) gilt ein dunkler Farbsatz mit `color-scheme: dark`.
- Admin-Menü (A396): Die Bereichsüberschriften im Pass-Captain-Menü („Verkauf“, „Einlass“ …) richten ihre Textfarbe nach dem Admin-Farbschema des Benutzers (Body-Klasse `admin-color-{schema}`, `Admin\Menu::MENU_HEADING_COLORS`): „Hell“ dunkelgrau `#50575e`, „Blau“ `#1d2327`, „Ozean“ und „Sonnenaufgang“ weiß, alle anderen (auch Schemata von Plugins) `#c3c4c7`. Mindestens 4,5:1 auf dem Untermenü, außer „Ozean“ (4,4:1, mehr lässt dessen Hintergrund nicht zu).
- Der Tastaturfokus im Kalender hat einen zweifarbigen Rahmen (Umriss plus Halo), der auf hellem und dunklem Grund sichtbar ist.
- Offline-Scanner (PWA): Statusmarken („offline“, OK/Warnung in der Liste) nutzen dunklere Texttöne (`--ok-text`, `--warn-text`); `--ok`/`--warn` bleiben Hintergründe hinter weißer Schrift. Alle Paare haben mindestens 4,5:1, Details im Ergebnisfeld werden nicht mehr abgeblendet; der Service-Worker-Cache wurde erhöht, damit installierte Apps das neue Stylesheet laden (A319).
