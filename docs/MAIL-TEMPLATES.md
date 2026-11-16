# E-Mail-Vorlagen

Alle E-Mails an Kund:innen laufen über den zentralen Mailer `PassCaptain\Mail\Mailer`. Jede Mail hat einen **Typ** mit eingebautem Standardtext – das ist genau der Text, den das Plugin bisher gesendet hat. Ohne eigene Vorlage wird dieser Text seit A101 **als HTML-Mail im Layout** versendet; die Textversion (AltBody) ist wortgleich mit der bisherigen Textmail (siehe [Standardformat](#standardformat-html-a101)). Im Admin unter *Pass Captain → E-Mail-Vorlagen* (Modul `PassCaptain\Modules\MailTemplates`, Code in `src/Mail/`) lassen sich Betreff und Text je Typ und Sprache überschreiben.

Admin-Benachrichtigungen (z. B. „Erstattung wartet auf Freigabe“, „Webhook deaktiviert“) bleiben einfache Textmails und sind nicht über Vorlagen anpassbar.

## Mail-Typen

| Typ | Wann | Wichtige Platzhalter |
|---|---|---|
| `tickets` | Tickets nach bestätigter Zahlung (mit PDF) | `{order_id}`, `{ticket_list}`, `{ticket_links}`, `{ticket_count}`, `{event_name}`, `{event_date}`, `{event_location}`, `{customer_email}`, `{contract_texts}` (A568: Bedingungen und Verbraucherinformationen in der Fassung der Bestellung mit Fassungskennung, aus dem Archiv; leer ohne Rechtstexte – steht im Standardtext nach der Ticketliste) |
| `tickets_resend` | Tickets erneut senden (neue Codes, je Empfänger:in) | wie `tickets`; `{ticket_list}` enthält je Ticket den Link und den Zusatztext aus `wpcap_ticket_code_mail_section` (z. B. Wallet-Links) |
| `order_access` | Persönlicher Link zur Bestellung | `{order_id}`, `{order_reference}`, `{access_link}`, `{order_items}` (bestellte Positionen), `{payment_instructions}`, `{contract_texts}` (wie bei `tickets`; nicht im Standardtext) |
| `ticket_transfer_recipient` | Ticket wurde an eine Person übertragen | `{event_name}`, `{ticket_name}`, `{event_date}`, `{event_location}`, `{ticket_link}`, `{ticket_extras}` |
| `ticket_transfer_sender` | Bestätigung der Übertragung an die Käufer:in | `{event_name}`, `{recipient_email}` |
| `ticket_assigned_attendee` | Ticket an zugewiesene Teilnehmer:in | `{attendee_name}`, `{attendee_email}`, `{ticket_link}`, `{ticket_extras}` … |
| `ticket_assigned_buyer` | Hinweis an die Käufer:in (alter Code ungültig) | `{attendee_name}`, `{attendee_email}`, `{event_name}`, `{ticket_name}`, `{event_date}` |
| `refund_confirmation` | Erstattung abgeschlossen (nicht bei WooCommerce) | `{refund_intro}` (Satz je Zahlart), `{refund_method_note}` (Auszahlungshinweis: Karte, bar, Überweisung mit IBAN bzw. Bitte um IBAN), `{refund_amount}`, `{order_id}` (kurze Bestellnummer), `{revoked_count}`, `{revoked_note}`, `{credit_note_number}`, `{credit_note}`, `{reason}` |
| `refund_request_received` | Stornierungsanfrage aus dem Bestellportal eingegangen (A118; Tickets bleiben bis zur Freigabe gültig) | `{order_id}` (kurze Bestellnummer), `{ticket_count}`, `{ticket_list}` (eine Zeile je Ticket), `{refund_amount}` (voraussichtlich), `{reason}`, `{portal_link}` |
| `attendee_data_request` | A682: Bitte um fehlende Teilnehmerangaben (Admin-Aktion „Fehlende Teilnehmerangaben anfordern“; neuer Bestelllink, der bisherige wird ungültig) | `{order_id}` (kurze Bestellnummer), `{missing_list}` (fehlende Angaben je Ticket), `{access_link}` |
| `refund_request_rejected` | Stornierungsanfrage aus dem Bestellportal abgelehnt (Tickets bleiben gültig; der interne Ablehnungsgrund wird nicht gesendet) | `{order_id}` (kurze Bestellnummer), `{ticket_count}` |
| `event_cancelled` | Ganzes Event abgesagt | `{event_name}` |
| `occurrence_cancelled` | Einzelner Termin abgesagt | `{event_name}`, `{event_date}`, `{event_location}`, `{timezone}`, `{refund_note}` |
| `occurrence_rescheduled` | Termin verschoben (nur die Zeit; aktualisierte .ics im Anhang) | `{event_name}`, `{old_date}`, `{event_date}`, `{timezone}`, `{event_location}`, `{ics_url}` |
| `occurrence_changed` | Termindetails geändert (A140): Ort, Ende, Einlass oder Veranstaltungsland – auch zusammen mit einer neuen Zeit, dann statt `occurrence_rescheduled` (eine Mail je Speichern). Höchstens einmal je Revision des Termins; aktualisierte .ics im Anhang | `{event_name}`, `{changes}` (eine Zeile „Angabe: bisher → neu“ je Änderung), `{event_date}`, `{old_date}`, `{timezone}`, `{event_location}`, `{ics_url}` |
| `waitlist_confirm` | Double-Opt-in nach der Eintragung (A564): Bestätigungslink; erst danach steht die Person auf der Warteliste | `{confirm_link}`, `{confirm_expires}`, `{confirm_hours}`, `{quantity}`, `{event_name}`, `{ticket_name}`, `{event_date}`, `{event_location}`, `{timezone}` |
| `waitlist_joined` | Eintragung in die Warteliste (mit Double-Opt-in nach der Bestätigung) | `{position}`, `{quantity}`, `{event_name}`, `{ticket_name}`, `{event_date}`, `{event_location}`, `{timezone}` |
| `waitlist_changed` | Beginn oder Ort des Termins geändert (A565), an Wartende und offene Angebote | `{event_name}`, `{ticket_name}`, `{old_date}`, `{old_location}`, `{event_date}`, `{event_location}`, `{timezone}`, `{quantity}`, `{status_note}`, `{unsubscribe_link}` |
| `waitlist_closed` | Wartelistenplatz beendet: Termin abgesagt, begonnen oder Verkauf beendet (A557) | `{event_name}`, `{ticket_name}`, `{reason}`, `{event_date}`, `{event_location}`, `{timezone}` |
| `waitlist_offer` | Platzangebot aus der Warteliste (Tickets bis zum Ablauf reserviert); der Standardtext nennt Termin mit Zeitzone und Ort (A565) | `{offer_expires}`, `{accept_link}`, `{quantity}`, `{position}`, `{event_name}`, `{ticket_name}`, `{event_date}`, `{event_location}`, `{timezone}` |
| `member_pass` | Mitgliedspass ausgestellt | `{pass_code}`, `{valid_from}`, `{valid_until}` |

In allen Mails stehen außerdem `{site_name}` (Filter `wpcap_sender_name`), `{site_url}` sowie `{terms_text}` und `{consumer_info_text}` zur Verfügung. Die Rechtstexte erscheinen in der Sprache der Mail (Locale der Bestellung) und fallen je Text auf die Standardsprache zurück, wenn für diese Sprache keine eigene Fassung gepflegt ist (**Einstellungen → Rechtstexte**). Die vollständige Liste mit Beschreibung zeigt der Editor je Typ.

Erinnerungen (Modul *Erinnerungen*) werden weiterhin auf ihrer eigenen Seite bearbeitet (nur Text); sie gehen über die Nachrichten-Warteschlange und ebenfalls über den zentralen Mailer (`Mailer::deliver`). Der fertig ersetzte Text wird als Ganzes maskiert, URLs werden verlinkt und in das HTML-Layout gesetzt (Format-Schlüssel `reminder` im Filter `wpcap_mail_default_format`).

**Käufersprache ohne WordPress-Sprachpaket (A306):** Mails werden in der Sprache der Bestellung gerendert (`Mailer::with_locale()`). `switch_to_locale()` lehnt Sprachen ab, für die kein WordPress-Sprachpaket installiert ist. Dann lädt der Mailer nur den Katalog des Plugins für diese Sprache (zuerst ein Sprachpaket in `wp-content/languages/plugins/pass-captain-<locale>.mo`, sonst die mitgelieferte Datei in `languages/`) und stellt danach den Katalog der Website wieder her. Texte des Plugins erscheinen so in der Käufersprache (mitgeliefert: en_US, es_ES, fr_FR, it_IT, nl_NL); WordPress-eigene Texte sowie Datums- und Zahlenformate bleiben in diesem Fall in der Website-Sprache. Gibt es auch keinen Plugin-Katalog, geht die Mail in der Website-Sprache hinaus.

**Leserichtung (A522):** HTML-Mails tragen `lang` und `dir` der Empfängersprache; bei Sprachen mit Schrift von rechts nach links (`Layout::RTL_LANGUAGES`: ar, he, fa, ur, ps, ckb, ug, yi …) steht `dir="rtl"` an `<html>`, `<body>` und der Inhaltstabelle, Texte sind rechtsbündig und die Beträge der Bestellübersicht stehen links. Filter `wpcap_mail_rtl`.

**Datums- und Uhrzeitformat (A384):** Mails, Tickets, Ticket-PDFs, Rechnungen, Kassen- und Abrechnungsbelege formatieren Datum und Uhrzeit zentral über `Frontend\Format::date_format()`/`time_format()` (bzw. `Format::local()`). In der Website-Sprache gelten die Formate aus *Einstellungen → Allgemein*; wird eine Mail oder ein Beleg in einer anderen Sprache gebaut (`Mailer::with_locale()`, auch wenn nur der Plugin-Katalog geladen werden konnte), gilt das Format dieser Sprache aus dem Plugin-Katalog (`_x( 'd.m.Y', 'date format' )` und `_x( 'H:i', 'time format' )`, Übersetzer:innen legen es je Sprache fest). Monats- und Wochentagsnamen kommen von `wp_date()`. Anpassbar mit den Filtern `wpcap_date_format` und `wpcap_time_format`.

**Fristen (A608):** Zahlungsfrist der Überweisung (`{payment_instructions}`, „Zahlbar bis“), Ablauf von Wartelistenangebot und -bestätigung sowie „Reserviert bis“ stehen in der Zeitzone des Termins mit genannter Zone, z. B. „24.12.2026 20:00 (Europe/Vienna)“ (`Frontend\Format::deadline()`) – wie das Termindatum derselben Mail. Ohne Termin gilt die Website-Zeitzone.

**Sprache und Ticketlink der Erinnerungen (A587):** Jede Erinnerung trägt die Sprache der Bestellung der Ticketinhaberin (`payload.locale`, ohne personenbezogene Daten) und wird darin gerendert – Texte, Datumswerte und das Layout. Auf der Erinnerungsseite lässt sich je installierter Sprache ein eigener Betreff/Text hinterlegen; ohne eigenen Text gilt der eingebaute Text in der Sprache der Empfängerin, solange der Text der Website-Sprache nicht geändert wurde (ein unverändert gespeicherter Standardtext wird als „Standard“ gespeichert), sonst der Text der Website-Sprache. Der Platzhalter `{ticket_links}` (im Standardtext enthalten) listet die gültigen Tickets der Empfängerin für den Termin mit ihrem persönlichen Ticketlink (Code aus dem verschlüsselten Speicher, A586; es wird nie ein neuer Code ausgestellt); ohne gespeicherten Code steht dort ein Hinweis auf die Ticketmail. Auf der Ticketseite kann der Bestelllink neu angefordert werden.

## Änderungsmitteilungen und Kalenderdateien (A140)

Jede Änderung an Beginn, Ende, Einlass, Ort, Veranstaltungsland oder Status eines Termins erhöht dessen **Revision** (`wpcap_occurrences.revision`, Schema 3.9.9) – egal ob im Termin-Formular, über eine Serienänderung, eine Terminabsage oder eine Eventabsage. Die Revision ist die `SEQUENCE` der .ics-Datei, `LAST-MODIFIED` der Zeitpunkt der Änderung, die `UID` bleibt je Termin gleich (`wpcap-occurrence-{id}@{host}`, identisch in Ticket-Mail, Änderungsmail, Download und Feed). Kalenderprogramme ersetzen dadurch den importierten Eintrag, statt einen zweiten anzulegen; abgesagte Termine kommen als `STATUS:CANCELLED`.

- **Eine Mail je Speichern:** Ändert sich nur die Zeit, geht wie bisher `occurrence_rescheduled`. Ändern sich Ort, Ende/Einlass ohne neuen Beginn oder das Veranstaltungsland – auch zusammen mit der Zeit –, geht stattdessen genau eine `occurrence_changed` mit allen Änderungen in `{changes}`.
- **Idempotent je Revision:** `wpcap_occurrences.notified_revision` merkt sich die zuletzt mitgeteilte Revision; ein erneuter Aufruf für dieselbe Revision sendet nichts.
- **Anhang und Link:** Beide Mails sowie `occurrence_cancelled` hängen die aktuelle .ics an, wenn unter *Kalender* „Kalenderdatei (.ics) je Termin anhängen“ aktiv ist; `{ics_url}` verlinkt den öffentlichen Download (leer, wenn das Modul Kalender abgeschaltet ist).
- **Abschalten je Event:** *Events → Event bearbeiten → Änderungsmitteilungen*. Ohne Haken gehen weder `occurrence_rescheduled` noch `occurrence_changed`; Absagen werden immer mitgeteilt.
- Empfänger sind wie bei der Terminänderung die Inhaber:innen gültiger Tickets (Teilnehmer-Adresse, sonst Käufer:in), je Adresse eine Mail in der Sprache der Bestellung. Im Änderungsverlauf des Termins erscheint der Eintrag „Ticketinhaber:innen über Änderung informiert“.

## Größe der Ticketmail (A580)

Gemessen: ein Ticket-PDF braucht rund 20 KB je Ticket, ein `.pkpass` 10–20 KB; Base64 im Mailversand kommt mit einem Drittel dazu. Ohne Grenze wäre eine Mail mit 1.000 Tickets 26–40 MB groß (Gmail und viele Postfächer nehmen 10–25 MB an), und die langen Google-Links ließen Gmail die Mail ab etwa 22 Tickets kürzen. Deshalb:

- **Geteilte PDFs:** höchstens 50 Tickets je PDF (`tickets-{bestellung}-1.pdf`, `-2.pdf` …; Filter `wpcap_ticket_pdf_chunk_size`). Angehängt wird, solange die Anhänge zusammen das **Anhangsbudget** von 7 MB nicht überschreiten (Filter `wpcap_ticket_mail_attachment_budget`, Bytes vor der Kodierung); das erste PDF immer. Fehlen Tickets in den Anhängen, sagt die Mail, welche die PDFs enthalten, und verweist auf die Ticketlinks und das Bestellportal.
- **Wallet:** bis 10 Tickets (Filter `wpcap_wallet_mail_inline_limit`) je Ticket ein `.pkpass` (solange es ins Budget passt, sonst Download-Link) und ein kurzer Google-Link; bei größeren Bestellungen stattdessen „Alle hinzufügen“-Links je Paket (Apple: `.pkpasses` mit 10 Pässen, Google: 8 Tickets je Link), siehe docs/WALLET.md.

## Standardformat: HTML (A101)

Prüfung der Formate (Stand A101):

| Mail | vorher | jetzt (ohne eigene Vorlage) |
|---|---|---|
| alle Typen der Registry (`tickets` … `member_pass`, auch per `wpcap_mail_types` ergänzte) | Text | **HTML** mit Layout + Textversion |
| Erinnerungen (Warteschlange, `reminder`) | Text | **HTML** mit Layout + Textversion |
| eigene Vorlagen (Option `wpcap_mail_templates`) | wie gespeichert | **unverändert** wie gespeichert (Text bleibt Text) |
| Admin-Benachrichtigungen (`wp_mail` direkt, z. B. „Webhook deaktiviert“) | Text | Text (keine Kundenmails) |

- Eingebaute Standardtexte sind Klartext mit Platzhaltern. Im HTML-Modus wird der Text maskiert (`esc_html`) und umbrochen (`nl2br`); Platzhalterwerte werden einzeln maskiert, Listen und Links (`{ticket_list}`, `{access_link}`, `{site_url}` …) bringen ihre sichere HTML-Darstellung mit.
- Die **Textversion** ist dieselbe Vorlage im Text-Modus gerendert (`Mailer::text_body()`), also exakt die bisherige Textmail – keine HTML-zu-Text-Umwandlung. Sie gilt nur, solange der HTML-Body unverändert ist: Hängt ein Altfilter (`wpcap_ticket_mail`) Text an, wird er auch an die Textversion angehängt; ersetzt ein Filter (`wpcap_ticket_mail`, `wpcap_mail`) den Body, wird die Textversion wie bei eigenen HTML-Vorlagen aus dem HTML erzeugt.
- **Entities in Namen (A497):** WordPress speichert ein einzelnes „<“ in Namen als `&lt;` (`sanitize_text_field()`); auch `&amp;` & Co. können in gespeicherten Namen stehen. In Textmails, Textversion und Betreff werden Platzhalterwerte deshalb mit `Text::plain()` dekodiert („Kids <3 Party“ statt „Kids &lt;3 Party“); im HTML-Teil bleiben sie maskiert. Dieselbe Regel gilt für alle anderen Klartext-Ausgaben: CSV-Exporte (vor der Formelprüfung), Kalender (iCalendar), Ticket-PDF, Namensschilder/Listen-PDF und Wallet-Pässe.
- Die HTML-zu-Text-Umwandlung gibt Tabellenzeilen (Zahlungsdaten, Bestellpositionen) als „Bezeichnung: Wert“ je Zeile aus.
- Zurück zu Textmails: Filter `wpcap_mail_default_format` (`'text'` für alle oder einzelne Typen) oder `'format' => 'text'` in der Typdefinition (`wpcap_mail_types`).
- Im Editor ist das Formular ohne eigene Vorlage mit Format *HTML* vorbelegt; erst Speichern legt eine eigene Vorlage an.

## Absender und Antwortadresse (A296)

Unter **Einstellungen → Allgemein → E-Mails an Käufer** stehen „Absendername“ und „Antwort an“. Ohne
Eintrag ist der Absendername der Name des Rechnungsstellers (sonst der Name der Website) und Antworten gehen
an die Administrator-E-Mail. Der Name wird nur während des Versands einer Plugin-Mail über
`wp_mail_from_name` gesetzt; die Absenderadresse bleibt die von WordPress bzw. des SMTP-Plugins (SPF/DKIM).
Jede Kunden-Mail des zentralen Mailers und die Mails an Kund:innen von Servicebuchungen erhalten den Header
`Reply-To`, sofern Vorlage oder Filter keinen eigenen setzen. Anpassen: Filter `wpcap_mail_sender`.

## Regeln für Platzhalter

- Schreibweise `{name}` (Kleinbuchstaben, Ziffern, Unterstrich). Ersetzung in **einem Durchgang**: Werte werden nicht erneut nach Platzhaltern durchsucht.
- Unbekannte Platzhalter werden durch einen leeren Text ersetzt.
- **Kein neuer Code an die bisherige Inhaber:in (A652):** `ticket_transfer_sender` und `ticket_assigned_buyer` erhalten weder `{ticket_link}` noch `{ticket_links}`, `{ticket_extras}` oder `{help_code}` (`Orders::without_code_vars()`); in eigenen Vorlagen bleiben diese Platzhalter dort leer. Den neuen Code bekommt nur die neue Inhaber:in.
- **Text-Modus:** Werte werden unverändert eingesetzt (keine HTML-Maskierung).
- **HTML-Modus:** Werte werden maskiert (`esc_html`, Zeilenumbrüche als `<br>`). Listen und Links (`{ticket_list}`, `{access_link}`, `{ticket_link}` …) bringen eine eigene, sichere HTML-Darstellung mit.
- Der Betreff ist immer einzeiliger Klartext.

## Editor

- **Übersicht:** alle Typen mit Beschreibung und den Sprachen, für die eigene Vorlagen existieren; darunter die Layout-Einstellungen.
- **Bearbeiten:** Sprache wählen (Reiter: Standardsprache der Website, installierte Sprachen, `en_US`, Sprachen mit Vorlagen), Betreff, Format (*Nur Text* oder *HTML*), Text. Ohne eigene Vorlage ist das Formular mit dem gerade gültigen Text vorbelegt.
- **Vorschau:** rendert den aktuellen, noch nicht gespeicherten Formularinhalt mit Beispieldaten (live per admin-ajax, `sandbox`-iframe).
- **Testmail senden:** sendet den Formularinhalt mit Beispieldaten an die eigene Adresse (Betreff mit Präfix „[Test]“; Empfänger per Filter `wpcap_mail_test_recipient` änderbar). Nichts wird gespeichert.
- **Auf Standard zurücksetzen:** löscht die eigene Vorlage dieser Sprache.

Alle Aktionen erfordern `manage_options` und die Nonce `wpcap_mail_templates`. Gespeichert wird: Betreff via `sanitize_text_field` (max. 250 Zeichen), Text-Vorlagen via `sanitize_textarea_field`, HTML-Vorlagen via `wp_kses_post` (max. 20 000 Zeichen).

Speicherort: Option `wpcap_mail_templates` (`[typ][locale] = {subject, body, format, updated_at}`), Layout im Einstellungsschlüssel `mail_layout`.

## HTML-Modus und Layout

HTML-Vorlagen werden in ein schlichtes, mailclient-taugliches Layout (Tabellen, Inline-Styles, max. 600 px) gesetzt:

- **Logo-URL** (leer: Custom Logo des Themes, sonst Name der Website als Text),
- **Akzentfarbe** (Rahmen und Links),
- **Fußzeile** (Klartext, z. B. Impressum; leer: Name der Website).

Text ohne HTML-Tags im HTML-Modus wird maskiert und automatisch umbrochen. Zu jeder HTML-Mail wird eine **Textversion** erzeugt (Links als „Text (URL)“, Absätze erhalten, Fußzeile angehängt) und über `phpmailer_init` als `AltBody` gesetzt – nur während des Versands dieser einen Mail.

Das fertige Dokument kann über `wpcap_mail_layout_html` ersetzt werden.

## Sprache der Empfänger:innen

Bestellungen speichern ihre Sprache (`locale`). Mails zu einer Bestellung (Tickets, Neuversand, Bestellzugang, Übertragung, Zuweisung, Erstattung, Absage, Terminänderung) werden in dieser Sprache gerendert: Der Mailer schaltet mit `switch_to_locale()` um und stellt danach die vorherige Sprache wieder her. Auswahl der Vorlage:

1. eigene Vorlage für die Sprache der Bestellung,
2. eigene Vorlage für die Standardsprache der Website,
3. eingebauter Text (übersetzt in die Sprache der Bestellung, sofern eine Übersetzung vorhanden ist).

**Aufbewahrung der Anhänge (A626):** Anhänge werden nicht mehr direkt nach `wp_mail()` gelöscht – Plugins, die Mails in eine Warteschlange stellen und später senden, fanden sonst keine Datei mehr. Sie liegen in privaten Verzeichnissen `wpcap-<zufall>` (0700, Dateien 0600) bzw. als `termin-<id>-<zufall>.ics` im Temp-Verzeichnis und werden von der stündlichen Bereinigung (`wpcap_cleanup_reservations`, `Mail\TempFiles::purge()`) nach `wpcap_mail_attachment_ttl` (Standard 6 Stunden) entfernt. Abwägung: Ticket-PDFs mit Klartext-Codes liegen so bis zu 6 Stunden auf dem Server; ohne Warteschlangen-Plugin kann der Filter auf `0` gesetzt werden (sofort löschen).

**Anhänge beim Neuversand (A621):** „Tickets erneut senden“ (Verwaltung, Bestellportal) und die automatische Wiederholung einer fehlgeschlagenen Ticketmail hängen dieselben Dateien an wie die Ticketmail: Ticket-PDF mit den Codes dieser Mail (gemeinsamer Baustein `Orders::ticket_pdf_attachments()`, gleiche Größenbegrenzung), `.ics` je Termin (Kalender-Modul) und `.pkpass` (Apple Wallet) über den Filter `wpcap_ticket_resend_attachments`.

**Anhänge und Zusätze (A474):** Auch was Module an die Ticketmail anhängen oder anfügen, entsteht in der Sprache der Bestellung: das Ticket-PDF, der Filter `wpcap_ticket_mail` (Rechnungs-PDF, Wallet-Texte und -Pässe, .ics, Sitzplätze) und der Filter `wpcap_ticket_code_mail_section` in Neuversand-, Übertragungs-, Zuweisungs-, Neuausstellungs- und Umtauschmails (`Orders::code_mail_section()`). Wallet-Pässe werden auch später (Abruf durch das Gerät, Google-Aktualisierung) in der Sprache der Bestellung gebaut; Google-Texte des Tickets tragen deren Sprachcode statt fest `de`; die für alle Käufer:innen gemeinsame Klasse die Website-Sprache mit Übersetzungen der Plugin-Texte (A518).

Bei Mails an mehrere Ticketinhaber:innen (Absagen, Terminänderungen, Erinnerungen) gilt die Sprache der jeweiligen Bestellung; hat eine Adresse mehrere Bestellungen, die der neuesten Bestellung mit gespeicherter Sprache (A519, `Notifications::LATEST_LOCALE_SQL`; vorher die alphabetisch letzte). Wartelisten- und Pass-Mails haben keine gespeicherte Sprache und nutzen die aktuelle.

## Für Entwickler:innen

```php
use PassCaptain\Mail\Mailer;

// Senden (Rückgabe: bool von wp_mail)
Mailer::send( 'order_access', $email, array(
	'order_id'    => $public_id,
	'access_link' => Mailer::link( $url ),               // Text: URL, HTML: <a>
), array( /* Anhänge */ ), $order->locale );

// Werte mit getrennter Text-/HTML-Darstellung
Mailer::block( $text, $sicheres_html );

// Werte, die übersetzt werden müssen: Closure, läuft in der Sprache der Empfänger:in
'refund_note' => static function () { return __( '…', 'pass-captain' ); },

// Rendern ohne Versand, dann ggf. filtern und senden
$message = Mailer::compose( 'tickets', $vars, $locale );
$message = Mailer::apply_filtered( $message, apply_filters( 'mein_filter', array( 'subject' => $message['subject'], 'body' => $message['body'], 'attachments' => array() ) ) );
Mailer::deliver( $to, $message );
```

Eigene Typen: Filter `wpcap_mail_types` mit `label`, `description`, `placeholders` (Schlüssel ohne Klammern → Beschreibung), `subject` und `body` (Callables, die den übersetzbaren Standardtext liefern) und `sample` (Callable mit Beispielwerten für Vorschau/Testmail). Sie erscheinen automatisch im Editor.

Bestehende Filter bleiben erhalten:

- `wpcap_ticket_mail` erhält weiterhin `subject`, `body` und `attachments` der Ticket-Mail – jetzt aus der (ggf. angepassten) Vorlage gerendert – und darf sie ändern. Im HTML-Modus wird von Filtern angehängter Klartext (Sitzplätze, Wallet-Links) maskiert und umbrochen, angehängtes HTML über `wp_kses_post` geführt.
- `wpcap_ticket_code_mail_section` wird pro Ticket in `{ticket_list}` (Neuversand) bzw. `{ticket_extras}` (Übertragung, Zuweisung) eingesetzt.

Weitere Hooks: `wpcap_mail` (Nachricht vor dem Versand), Action `wpcap_mail_before_send` (finale `wp_mail`-Argumente inkl. `alt_body`). Siehe [HOOKS.md](HOOKS.md).
