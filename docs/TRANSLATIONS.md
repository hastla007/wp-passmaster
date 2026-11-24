# Übersetzungen

Quellsprache ist Deutsch. Mitgeliefert: Englisch (en_US), Spanisch (es_ES), Französisch (fr_FR),
Italienisch (it_IT), Niederländisch (nl_NL) in `languages/` (`.po` Quelle, `.mo` zur Laufzeit,
`.pot` Vorlage). Die Offline-App hat einen eigenen Katalog in `assets/offline-scanner/i18n.js`.

## Laden zur Laufzeit (A382)

Das Plugin ruft `load_plugin_textdomain()` nicht mehr auf (Plugin Check meldet das für WordPress.org).
`Localization::register_textdomain()` trägt beim Laden nur `languages/` als Ausweichpfad im
Textdomain-Register von WordPress ein; geladen wird der Katalog erst beim ersten übersetzten Text
(Just-in-time-Laden, wie bei jedem Plugin von WordPress.org). Dabei gewinnt ein Sprachpaket aus
`wp-content/languages/plugins/` (translate.wordpress.org); nur ohne Sprachpaket kommt die mitgelieferte
`.mo` zum Zug. Nach einem Sprachwechsel für eine einzelne Mail (A306) gibt
`Localization::reload_textdomain()` den Katalog wieder frei, er wird dann in der aktuellen Sprache neu geladen.

Die mitgelieferten Übersetzungen bleiben damit wirksam, auch solange das Plugin nicht über
translate.wordpress.org ausgeliefert wird: Das Just-in-time-Laden von WordPress (ab 6.1) sucht zuerst in
`WP_LANG_DIR/plugins/` und fällt danach auf den eingetragenen Pfad `languages/` zurück.

## Ablauf bei neuen oder geänderten Texten

```bash
wp i18n make-pot . languages/pass-captain.pot --domain=pass-captain --exclude=vendor,vendor-prefixed,tests,build,.claude,node_modules --skip-audit --skip-theme-json
```

```bash
for l in en_US es_ES fr_FR it_IT nl_NL; do msgmerge -q --no-wrap -U --backup=off languages/pass-captain-$l.po languages/pass-captain.pot; msgattrib --no-wrap --no-obsolete -o languages/pass-captain-$l.po languages/pass-captain-$l.po; done
```

Danach die neuen und als `fuzzy` markierten Einträge übersetzen bzw. prüfen (Fuzzy-Vorschläge sind
oft falsch), `.mo` mit `php build/compile-mo.php <po> <mo>` erzeugen und `php tests/i18n-check.php`
ausführen. CI bricht ab, wenn die `.pot` nicht zum Code passt, Platzhalter (`%s`, `%1$s`,
`{order_id}`) oder HTML-Tags abweichen oder eine `.mo` veraltet ist. Fehlende oder fuzzy Einträge sind
zwischen zwei Releases erlaubt; der Release-Workflow verlangt dagegen vollständige Übersetzungen.

Regeln für Quelltexte: Anrede „du“ (Rechtstexte/Datenschutzhinweise ausgenommen); Platzhalter immer
über `sprintf` mit Übersetzerkommentar (`/* translators: … */` direkt vor dem `__()`); gleiche Wörter
mit unterschiedlicher Bedeutung mit Kontext (`_x()`); keine Textbausteine zusammensetzen.

Mengen (A383): nie „Ticket(s)“ oder „Bestellung(en)“, sondern `_n()` mit `sprintf`. Texte, deren
Anzahl erst im Browser feststeht, bekommen eine Einzahl-Variante mit dem Schlüssel `…One`
(`syncCountOne`, `syncedOne`, `expiredOne`, `sumOne`, `errTotalOne`), die das Skript bei genau 1
nimmt. „Status: %s“ ist ein Satz (`Forms::status_line()`), kein angehängtes Label. Die Regel prüft
`tests/integration/modules/zz_a383.php`.

Kontexte (A515): „Einlass“ gibt es als Einlasszeit (`doors open time`), Arbeitsbereich/Menü
(`admission (check-in area of work)`), Scanner-Ergebnis (`scanner result: entry granted`), Scan-Richtung
(`scanner direction: entry (opposite of exit)`) und Pass-Regel; „Offen“ als Restmenge, Konfliktstatus und
Kassenschicht-Status. Alle Statuswerte von `Forms::status_label()` stehen im Kontext `status`
(Übersetzungen können so z. B. das grammatische Geschlecht des Status wählen).

Sortierung (A520): Listen mit Namen (z. B. Gruppen der Berichte) sortiert `Localization::compare()` in der Website-Sprache (ICU-Collator, Zahlen natürlich; ohne intl-Erweiterung ohne Akzente und Groß/Klein), nicht nach Bytes.

## Sprache der Käufer:in (W9, A165)

Ist unter **Einstellungen → Sicherheit** „Sprachauswahl im Checkout und im Bestellportal anzeigen“ an
(Standard) und gibt es mehr als eine Sprache, zeigt der Checkout ein Auswahlfeld `buyer_locale`
(`Frontend\Language`). Es bestimmt die Sprache von E-Mails, Tickets, Ticketseite und Bestellportal
(`wpcap_orders.locale`); im Portal lässt sie sich später ändern. Das versteckte Feld `locale` nennt
weiterhin die Sprache, in der die Seite und damit die Rechtstexte angezeigt wurden: Zustimmung,
gespeicherte Bedingungsfassung (`terms_version`) und die Vertragstexte in der Bestätigungsmail
(`{contract_texts}`) sind immer die gezeigte Fassung, auch wenn für Mails eine andere Sprache gewählt
wurde. Eine nicht angebotene Auswahl fällt auf die Seitensprache zurück; bei ausgeschalteter Auswahl
wird `buyer_locale` ignoriert.

**String-Prüfung:** `tests/i18n-check.php` prüft die Kataloge (siehe unten); `tests/consistency.php`
prüft zusätzlich, dass jeder Schlüssel, den ein Frontend-Skript aus seinem übersetzten Katalog liest
(`t.…` in `assets/checkout.js`, `service-booking.js`, `woocommerce/attendees.js`, `seating/*.js`), im
PHP-Katalog definiert ist – sonst sähe die Käufer:in den Platzhalter des Skripts statt des übersetzten
Textes.

## Stand und Prüfung (A107)

Stand 13.11.2026: alle 3234 Texte in allen fünf Sprachen vollständig, 0 fuzzy. Die 630 alten
Fuzzy-Vorschläge wurden einzeln gegen den aktuellen Quelltext geprüft und fast alle neu übersetzt;
471 neue Texte wurden ergänzt. Übersetzt und gegengelesen wurde KI-gestützt, je Sprache mit einem
festen Glossar (Auszug unten) und Abgleich mit dem Code bei unklarer Bedeutung.

**Offen:** eine Durchsicht durch Muttersprachler mit Fachkenntnis (Ticketing/Kasse/Steuern). Bitte
Befunde als Änderungen an der jeweiligen `.po` einreichen.

| Deutsch | en_US | es_ES | fr_FR | it_IT | nl_NL |
|---|---|---|---|---|---|
| Termin | date | fecha | date | data | datum |
| Ticketart | ticket type | tipo de entrada | type de ticket | tipo di biglietto | tickettype |
| Einlass | admission | acceso | entrée | ingresso | toegang |
| Kasse (Gerät) / Schicht | register / shift | caja / turno | caisse / session | cassa / turno | kassa / dienst |
| Rechnungskorrektur | invoice correction | corrección de factura | facture rectificative | rettifica fattura | factuurcorrectie |
| Gutschein | voucher | bono | bon | buono | cadeaubon (steuerlich: voucher) |
| Veranstalter / Wiederverkäufer | organizer / reseller | organizador / revendedor | organisateur / revendeur | organizzatore / rivenditore | organisator / wederverkoper |
| Warteliste / Gästeliste | waitlist / guest list | lista de espera / lista de invitados | liste d’attente / liste d’invités | lista d’attesa / lista ospiti | wachtlijst / gastenlijst |
| Hilfscode | short code | código auxiliar | code de secours | codice di supporto | hulpcode |
| Anrede | you | tú | vous | tu | je |
