# E-Mail-Vorlagen

Alle E-Mails an Kund:innen laufen über den zentralen Mailer `PassCaptain\Mail\Mailer`. Jede Mail hat einen **Typ** mit eingebautem Standardtext – das ist genau der Text, den das Plugin bisher gesendet hat. Ohne eigene Vorlage ändert sich also nichts. Im Admin unter *Pass Captain → E-Mail-Vorlagen* (Modul `PassCaptain\Modules\MailTemplates`, Code in `src/Mail/`) lassen sich Betreff und Text je Typ und Sprache überschreiben.

Admin-Benachrichtigungen (z. B. „Erstattung wartet auf Freigabe“, „Webhook deaktiviert“) bleiben einfache Textmails und sind nicht über Vorlagen anpassbar.

## Mail-Typen

| Typ | Wann | Wichtige Platzhalter |
|---|---|---|
| `tickets` | Tickets nach bestätigter Zahlung (mit PDF) | `{order_id}`, `{ticket_list}`, `{ticket_links}`, `{ticket_count}`, `{event_name}`, `{event_date}`, `{event_location}`, `{customer_email}`, `{consumer_info}`, `{terms}` |
| `tickets_resend` | Tickets erneut senden (neue Codes, je Empfänger:in) | wie `tickets`; `{ticket_list}` enthält je Ticket den Link und den Zusatztext aus `wpcap_ticket_code_mail_section` (z. B. Wallet-Links) |
| `order_access` | Persönlicher Link zur Bestellung | `{order_id}`, `{access_link}`, `{payment_instructions}`, `{consumer_info}`, `{terms}` |
| `ticket_transfer_recipient` | Ticket wurde an eine Person übertragen | `{event_name}`, `{ticket_name}`, `{event_date}`, `{event_location}`, `{ticket_link}`, `{ticket_extras}` |
| `ticket_transfer_sender` | Bestätigung der Übertragung an die Käufer:in | `{event_name}`, `{recipient_email}` |
| `ticket_assigned_attendee` | Ticket an zugewiesene Teilnehmer:in | `{attendee_name}`, `{attendee_email}`, `{ticket_link}`, `{ticket_extras}` … |
| `ticket_assigned_buyer` | Hinweis an die Käufer:in (alter Code ungültig) | `{attendee_name}`, `{attendee_email}`, `{event_name}`, `{ticket_name}`, `{event_date}` |
| `refund_confirmation` | Erstattung abgeschlossen (nicht bei WooCommerce) | `{refund_amount}`, `{order_id}`, `{revoked_count}`, `{revoked_note}`, `{credit_note_number}`, `{credit_note}`, `{reason}` |
| `event_cancelled` | Ganzes Event abgesagt | `{event_name}` |
| `occurrence_cancelled` | Einzelner Termin abgesagt | `{event_name}`, `{event_date}`, `{event_location}`, `{timezone}`, `{refund_note}` |
| `occurrence_rescheduled` | Termin verschoben | `{event_name}`, `{old_date}`, `{event_date}`, `{timezone}`, `{event_location}`, `{refund_note}` (Erstattungshinweis mit Frist, siehe [OPERATIONS.md](OPERATIONS.md#terminverschiebung-erstattungsantrag-im-bestellportal)) |
| `waitlist_confirm` | Double-Opt-in: Bestätigungslink nach der Anmeldung zur Event-Warteliste (Einstellung `waitlist_double_opt_in`, Standard an; Link 48 Stunden gültig) | `{confirm_link}`, `{confirm_expires}`, `{event_name}`, `{ticket_name}` |
| `waitlist_joined` | Eintragung in die Warteliste (bei Double-Opt-in erst nach der Bestätigung) | `{position}`, `{event_name}`, `{ticket_name}` |
| `waitlist_offer` | Platzangebot aus der Warteliste | `{offer_expires}`, `{accept_link}`, `{position}`, `{event_name}`, `{ticket_name}` |
| `service_waitlist_confirm` | Double-Opt-in: Bestätigungslink nach der Anmeldung zur Warteliste eines Zeitfensters | `{confirm_link}`, `{confirm_expires}`, `{resource_name}`, `{slot_date}`, `{quantity}` |
| `member_pass` | Mitgliedspass ausgestellt | `{pass_code}`, `{valid_from}`, `{valid_until}` |

`{consumer_info}` und `{terms}` setzen die Texte „Verbraucherinformationen“ und „AGB / Teilnahmebedingungen“
aus *Einstellungen → Rechtstexte* mit Überschrift ein (Vertragsbestätigung auf einem dauerhaften
Datenträger, vgl. § 312f Abs. 2 BGB / § 7 Abs. 3 FAGG); ist ein Text leer, bleibt der Platzhalter leer.
Die Standardtexte von `tickets` und `order_access` enthalten beide Platzhalter (ohne gepflegte Texte
ändert sich nichts). Eigene Vorlagen müssen sie selbst aufnehmen. Ob das im Einzelfall genügt (z. B. AGB
zusätzlich als PDF), ist rechtlich zu prüfen.

In allen Mails stehen außerdem `{site_name}` (Filter `wpcap_sender_name`) und `{site_url}` zur Verfügung. Die vollständige Liste mit Beschreibung zeigt der Editor je Typ.

Erinnerungen (Modul *Erinnerungen*) werden weiterhin auf ihrer eigenen Seite bearbeitet; sie gehen über die Nachrichten-Warteschlange und ebenfalls über den zentralen Mailer (`Mailer::deliver`).

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

## Absender und Zustellbarkeit

**Einstellungen → E-Mail-Versand:**

- **Absenderadresse** (`mail_from_email`): From-Header aller Kunden-Mails des Plugins und – nur während das Plugin
  sendet – Envelope-Sender/Return-Path (`$phpmailer->Sender`, `phpmailer_init` Priorität 5). Leer: kein From-Header,
  WordPress sendet als `wordpress@<host>`.
- **Absendername** (`mail_from_name`, leer: Website-Titel; ersetzt „WordPress“ über `wp_mail_from_name` Priorität 5).
- **Antwortadresse** (`mail_reply_to`): Reply-To-Header, z. B. das Support-Postfach.

Ein SMTP-Plugin (WP Mail SMTP, FluentSMTP, Post SMTP …), das einen eigenen Absender erzwingt, hat weiterhin Vorrang:
Es hängt sich später ein bzw. setzt den Sender selbst; ein schon gesetzter Envelope-Sender wird nicht überschrieben.
Mails anderer Plugins bleiben unberührt (die Hooks gelten nur für die Dauer des eigenen Versands).

Damit Tickets nicht im Spam landen:

1. Absenderadresse mit der **Domain der Website** wählen (z. B. `tickets@ihre-domain.de`).
2. **SPF**: Der DNS-TXT-Eintrag der Domain muss den versendenden Server bzw. SMTP-Dienst erlauben
   (z. B. `v=spf1 include:_spf.ihr-dienst.example ~all`).
3. **DKIM**: Mails signieren – praktisch nur über einen SMTP-/Versanddienst oder den Mailserver des Hosters.
4. **DMARC**: TXT-Eintrag `_dmarc.ihre-domain.de` (z. B. `v=DMARC1; p=none; rua=mailto:…`); er verlangt, dass
   SPF oder DKIM zur From-Domain passt („Alignment“) – genau dafür setzt das Plugin From und Return-Path gleich.
5. Für mehr als ein paar Dutzend Mails am Tag einen **SMTP-Dienst** (Postmark, SendGrid, Mailgun, Amazon SES, Brevo …)
   über ein SMTP-Plugin verwenden; PHP `mail()` des Webservers wird von großen Anbietern oft abgelehnt.

Der **Systemstatus** warnt, wenn keine Absenderadresse eingestellt ist oder ihre Domain nicht zur Website passt.

**Massenmails** (Terminverschiebung, Event-Absage, Terminabsage) gehen je Empfänger:in über die E-Mail-Queue
(Dedupe-Schlüssel `resched|Termin|neuer Beginn|E-Mail`, `event-cancel|Event|E-Mail`, `occ-cancel|Termin|Lauf|E-Mail`):
die ersten werden noch im Admin-Request versendet (bis ~10 s), der Rest vom Worker; jede Mail wird einzeln mit
Backoff wiederholt, nichts doppelt gesendet. Die Admin-Meldung nennt „versendet / im Hintergrund / fehlgeschlagen“.

**Ticket-Mails großer Bestellungen** (über 10 Tickets, Filter `wp_pass_captain_ticket_mail_inline_max_v1`) und jede
Bestellung, die ein Zahlungs-Webhook bestätigt, werden nicht im Request gerendert: Der Outbox-Job ist sofort fällig
und mailt neue Codes (die bei der Ausstellung erzeugten Codes sah niemand; Klartext-Codes werden nie gespeichert,
auch nicht verschlüsselt). Das PDF hängt bis 200 Tickets an (`wp_pass_captain_ticket_pdf_max_tickets_v1`); mit PDF
listet der Text nur die ersten 25 Tickets (`wp_pass_captain_ticket_mail_list_max_v1`). Dieselben Regeln gelten für
Zustell-Retry, „Tickets erneut senden“ und Neuausstellung.

**Erinnerungen** enthalten einen persönlichen Abmeldelink und die Header `List-Unsubscribe` /
`List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058). Der Link zeigt per GET nur eine Bestätigung (Link-Scanner
melden niemanden ab); erst POST trägt die Adresse aus (Tabelle `wpcap_mail_suppressions`, nur SHA-256 der Adresse).
Die Abmeldung gilt für alle Erinnerungen an diese Adresse, nicht für Tickets, Absagen oder Erstattungen.

**Bounces und Beschwerden** (optional, Einstellung „Bounce-/Beschwerde-Webhook“): Die angezeigte URL (mit geheimem
Token, alternativ Header `X-WPCAP-Bounce-Token`) beim Versanddienst eintragen. Verstanden werden Postmark, SendGrid,
Mailgun, Amazon SES (über SNS) und `{"email": "…", "type": "bounce|complaint"}`. Harte Bounces markieren die
Queue-Mails der letzten 14 Tage an die Adresse als fehlgeschlagen und erscheinen als Hinweis in der Bestellung;
Spam-Beschwerden beenden zusätzlich die Erinnerungen.

**HTML als Standard?** Die eingebauten Vorlagen bleiben Text: Ihre Ausgabe ist seit jeher identisch, und Filter wie
`wpcap_ticket_mail` hängen Klartext an (Sitzplätze, Wallet-Links). Eine Umstellung des Standards würde die Mails
bestehender Installationen sichtbar ändern. HTML ist je Vorlage ein Klick (mit automatischer Textversion).

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
