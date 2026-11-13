# E-Mail-Vorlagen

Alle E-Mails an Kund:innen laufen über den zentralen Mailer `PassCaptain\Mail\Mailer`. Jede Mail hat einen **Typ** mit eingebautem Standardtext – das ist genau der Text, den das Plugin bisher gesendet hat. Ohne eigene Vorlage wird dieser Text seit A101 **als HTML-Mail im Layout** versendet; die Textversion (AltBody) ist wortgleich mit der bisherigen Textmail (siehe [Standardformat](#standardformat-html-a101)). Im Admin unter *Pass Captain → E-Mail-Vorlagen* (Modul `PassCaptain\Modules\MailTemplates`, Code in `src/Mail/`) lassen sich Betreff und Text je Typ und Sprache überschreiben.

Admin-Benachrichtigungen (z. B. „Erstattung wartet auf Freigabe“, „Webhook deaktiviert“) bleiben einfache Textmails und sind nicht über Vorlagen anpassbar.

## Mail-Typen

| Typ | Wann | Wichtige Platzhalter |
|---|---|---|
| `tickets` | Tickets nach bestätigter Zahlung (mit PDF) | `{order_id}`, `{ticket_list}`, `{ticket_links}`, `{ticket_count}`, `{event_name}`, `{event_date}`, `{event_location}`, `{customer_email}` |
| `tickets_resend` | Tickets erneut senden (neue Codes, je Empfänger:in) | wie `tickets`; `{ticket_list}` enthält je Ticket den Link und den Zusatztext aus `wpcap_ticket_code_mail_section` (z. B. Wallet-Links) |
| `order_access` | Persönlicher Link zur Bestellung | `{order_id}`, `{order_reference}`, `{access_link}`, `{order_items}` (bestellte Positionen), `{payment_instructions}` |
| `ticket_transfer_recipient` | Ticket wurde an eine Person übertragen | `{event_name}`, `{ticket_name}`, `{event_date}`, `{event_location}`, `{ticket_link}`, `{ticket_extras}` |
| `ticket_transfer_sender` | Bestätigung der Übertragung an die Käufer:in | `{event_name}`, `{recipient_email}` |
| `ticket_assigned_attendee` | Ticket an zugewiesene Teilnehmer:in | `{attendee_name}`, `{attendee_email}`, `{ticket_link}`, `{ticket_extras}` … |
| `ticket_assigned_buyer` | Hinweis an die Käufer:in (alter Code ungültig) | `{attendee_name}`, `{attendee_email}`, `{event_name}`, `{ticket_name}`, `{event_date}` |
| `refund_confirmation` | Erstattung abgeschlossen (nicht bei WooCommerce) | `{refund_intro}` (Satz je Zahlart), `{refund_method_note}` (Auszahlungshinweis: Karte, bar, Überweisung mit IBAN bzw. Bitte um IBAN), `{refund_amount}`, `{order_id}` (kurze Bestellnummer), `{revoked_count}`, `{revoked_note}`, `{credit_note_number}`, `{credit_note}`, `{reason}` |
| `refund_request_received` | Stornierungsanfrage aus dem Bestellportal eingegangen (A118; Tickets bleiben bis zur Freigabe gültig) | `{order_id}` (kurze Bestellnummer), `{ticket_count}`, `{ticket_list}` (eine Zeile je Ticket), `{refund_amount}` (voraussichtlich), `{reason}`, `{portal_link}` |
| `refund_request_rejected` | Stornierungsanfrage aus dem Bestellportal abgelehnt (Tickets bleiben gültig; der interne Ablehnungsgrund wird nicht gesendet) | `{order_id}` (kurze Bestellnummer), `{ticket_count}` |
| `event_cancelled` | Ganzes Event abgesagt | `{event_name}` |
| `occurrence_cancelled` | Einzelner Termin abgesagt | `{event_name}`, `{event_date}`, `{event_location}`, `{timezone}`, `{refund_note}` |
| `occurrence_rescheduled` | Termin verschoben | `{event_name}`, `{old_date}`, `{event_date}`, `{timezone}`, `{event_location}` |
| `waitlist_joined` | Eintragung in die Warteliste | `{position}`, `{quantity}`, `{event_name}`, `{ticket_name}` |
| `waitlist_offer` | Platzangebot aus der Warteliste (Tickets bis zum Ablauf reserviert) | `{offer_expires}`, `{accept_link}`, `{quantity}`, `{position}`, `{event_name}`, `{ticket_name}` |
| `member_pass` | Mitgliedspass ausgestellt | `{pass_code}`, `{valid_from}`, `{valid_until}` |

In allen Mails stehen außerdem `{site_name}` (Filter `wpcap_sender_name`), `{site_url}` sowie `{terms_text}` und `{consumer_info_text}` zur Verfügung. Die Rechtstexte erscheinen in der Sprache der Mail (Locale der Bestellung) und fallen je Text auf die Standardsprache zurück, wenn für diese Sprache keine eigene Fassung gepflegt ist (**Einstellungen → Rechtstexte**). Die vollständige Liste mit Beschreibung zeigt der Editor je Typ.

Erinnerungen (Modul *Erinnerungen*) werden weiterhin auf ihrer eigenen Seite bearbeitet (nur Text); sie gehen über die Nachrichten-Warteschlange und ebenfalls über den zentralen Mailer (`Mailer::deliver`). Der fertig ersetzte Text wird als Ganzes maskiert, URLs werden verlinkt und in das HTML-Layout gesetzt (Format-Schlüssel `reminder` im Filter `wpcap_mail_default_format`).

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
- Die HTML-zu-Text-Umwandlung gibt Tabellenzeilen (Zahlungsdaten, Bestellpositionen) als „Bezeichnung: Wert“ je Zeile aus.
- Zurück zu Textmails: Filter `wpcap_mail_default_format` (`'text'` für alle oder einzelne Typen) oder `'format' => 'text'` in der Typdefinition (`wpcap_mail_types`).
- Im Editor ist das Formular ohne eigene Vorlage mit Format *HTML* vorbelegt; erst Speichern legt eine eigene Vorlage an.

## Regeln für Platzhalter

- Schreibweise `{name}` (Kleinbuchstaben, Ziffern, Unterstrich). Ersetzung in **einem Durchgang**: Werte werden nicht erneut nach Platzhaltern durchsucht.
- Unbekannte Platzhalter werden durch einen leeren Text ersetzt.
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

Bei Mails an mehrere Ticketinhaber:innen (Absagen, Terminänderungen) gilt die Sprache der jeweiligen Bestellung. Wartelisten- und Pass-Mails haben keine gespeicherte Sprache und nutzen die aktuelle.

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
'refund_note' => static function () { return __( '…', 'wp-pass-captain' ); },

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
