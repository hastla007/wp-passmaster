# Übersetzungen

Quellsprache ist Deutsch. Mitgeliefert: Englisch (en_US), Spanisch (es_ES), Französisch (fr_FR),
Italienisch (it_IT), Niederländisch (nl_NL) in `languages/` (`.po` Quelle, `.mo` zur Laufzeit,
`.pot` Vorlage). Die Offline-App hat einen eigenen Katalog in `assets/offline-scanner/i18n.js`.

## Ablauf bei neuen oder geänderten Texten

```bash
wp i18n make-pot . languages/wp-pass-captain.pot --domain=wp-pass-captain --exclude=vendor,tests,build,.claude,node_modules --skip-audit --skip-block-json --skip-theme-json
```

```bash
for l in en_US es_ES fr_FR it_IT nl_NL; do msgmerge -q --no-wrap -U --backup=off languages/wp-pass-captain-$l.po languages/wp-pass-captain.pot; msgattrib --no-wrap --no-obsolete -o languages/wp-pass-captain-$l.po languages/wp-pass-captain-$l.po; done
```

Danach die neuen und als `fuzzy` markierten Einträge übersetzen bzw. prüfen (Fuzzy-Vorschläge sind
oft falsch), `.mo` mit `php build/compile-mo.php <po> <mo>` erzeugen und `php tests/i18n-check.php`
ausführen. CI bricht ab, wenn die `.pot` nicht zum Code passt, ein Eintrag fehlt oder fuzzy ist,
Platzhalter (`%s`, `%1$s`, `{order_id}`) oder HTML-Tags abweichen oder eine `.mo` veraltet ist.

Regeln für Quelltexte: Anrede „du“ (Rechtstexte/Datenschutzhinweise ausgenommen); Platzhalter immer
über `sprintf` mit Übersetzerkommentar (`/* translators: … */` direkt vor dem `__()`); gleiche Wörter
mit unterschiedlicher Bedeutung mit Kontext (`_x()`); keine Textbausteine zusammensetzen.

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
