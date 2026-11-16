# Schnittstellenverträge und Versionierung

Pass Captain bietet mehrere Schnittstellen, auf die sich andere Programme verlassen: die öffentliche REST-API, die Partner-API, die Entwickler-API, die Mobile-App-API, den Offline-Scanner, die Brücke zu Voucher Captain, den Adapter für Affiliate Captain, den Workflow-Providervertrag und die öffentlichen Hooks für Add-ons. Für jede Schnittstelle gilt dieselbe Versionsregel. Die Prüfung steht in `src/Contracts.php`.

## Versionsregel

Jede Schnittstelle hat eine Vertragsversion `MAJOR.MINOR`:

- **Minor-Version** (z. B. 1.0 → 1.1): Es kommen nur Felder, Endpunkte oder optionale Parameter hinzu. Ein bestehendes Feld wird nicht entfernt, nicht umbenannt und ändert seinen Typ nicht (`int` bleibt `int`, ein Objekt wird keine Liste, ein Pflichtfeld wird nicht optional). Optionale Felder dürfen fehlen, wenn sie dokumentiert optional sind.
- **Major-Version** (z. B. 1.x → 2.0): Nur hier sind inkompatible Änderungen erlaubt. Eine neue Hauptversion bekommt einen neuen Filter- bzw. Namensraum (z. B. `wp_pass_captain_voucher_provider_v2`). Die alte Version läuft mindestens eine Minor-Version des Plugins parallel und wird in `CHANGELOG.md` angekündigt.
- **Gegenseite**: Clients und Anbieter müssen unbekannte zusätzliche Felder ignorieren. Pass Captain tut das ebenfalls: Unbekannte Felder in Requests, in Antworten von Voucher Captain oder von Affiliate Captain werden ignoriert (bei Voucher Captain unverändert gespeichert und zurückgegeben, aber nie ausgewertet).

| Schnittstelle | Vertrag | Version | Dokumentation |
|---|---|---|---|
| Öffentliche REST-API (`pass-captain/v1`: Events, Checkout, Bestellportal, …) | `public` | 1.1 (1.1: `/events` mit Zeiten in ISO 8601 mit Offset, Phase, Revision und Verkaufsstatus je Ticketart; Checkout-Positionen mit optionalem `occurrence_revision`/`expected_price_minor`, siehe [OPERATIONS.md](OPERATIONS.md#checkout-an-den-angezeigten-stand-gebunden-a560)) | [SECURITY.md](SECURITY.md) |
| Partner-API (`/partner/…`) | `partner` | 1.0 (Header `X-WPCAP-Partner-API: 1`) | [PARTNER-API.md](PARTNER-API.md) |
| Entwickler-API (`X-WPCAP-Key`) | `developer` | 1.0 | [SECURITY.md](SECURITY.md) |
| Mobile-App-API (`/app/…`) | `app` | 1.1 (`api_version` in `/app/me`) | [MOBILE-API.md](MOBILE-API.md) |
| Offline-Scanner (`/app/offline/…`, Paketformat `v`) | `offline` | 1.0 | [OFFLINE-SCANNER.md](OFFLINE-SCANNER.md) |
| Gutschein-Anbieter (`wp_pass_captain_voucher_provider_v1`) | `voucher_provider` | 1.0 | [VOUCHERS.md](VOUCHERS.md) |
| Ticketdienst und Check-in-Dienst für Voucher Captain (`wp_pass_captain_ticket_service_v1`, `wp_pass_captain_checkin_service_v1`) | `ticket_service`, `checkin_service` | 1.0 | [VOUCHERS.md](VOUCHERS.md) |
| Affiliate Captain (`wpac_record_sale()`, `wpac_record_refund()`) | `affiliate` | 1.0.0 | [EXTENSIONS.md](EXTENSIONS.md) |
| Ausgehende Webhooks (signierte POSTs, [WEBHOOKS.md](WEBHOOKS.md)) | `webhooks` | `api_version` 2026-11-01, `schema_version` je Ereignistyp (1.0; `order.refunded` 1.1) | [WEBHOOKS.md](WEBHOOKS.md) |
| Workflow-Provider (`wp_pass_captain_workflow_provider_v1`, Ereignis-Outbox) | `workflow` | 1.0.0 (`PassCaptain\Workflow\Contract::VERSION`, Schema-Version je Ereignistyp) | [unten](#workflow-providervertrag-v1) |
| Workflow-Commands (`PassCaptain\Workflow\Commands::execute()`) | `workflow_commands` | 1.0.0 (`Commands::VERSION`) | [unten](#workflow-commands-v1) |
| Review-Provider (`wp_pass_captain_review_provider_v1`, Teilnahmevertrag `experience_completed`) | `review` | 1.0.0 (`PassCaptain\Review\Contract::VERSION`) | [unten](#review-teilnahmevertrag-v1) |
| Öffentliche Add-on-API (Hooks für `pass-captain-pro` und andere Plugins, `wpcap_loaded`) | `addon` | 1.0.0 (`PassCaptain\PublicApi::VERSION`) | [HOOKS.md](HOOKS.md#öffentliche-add-on-api) |

## REST: Versionsangabe des Clients

Ein Client kann die Version, gegen die er gebaut ist, im Header `X-WPCAP-Contract` mitschicken (`1.0`, `1.4`, `1`):

- Gleiche Hauptversion (jede Minor-Version, auch eine neuere als die des Servers): Die Anfrage läuft normal.
- Andere Hauptversion oder kein gültiger Versionswert: Antwort `400` mit Code `wpcap_contract_version`, bevor Berechtigungsprüfung oder Handler laufen. `data` enthält `api`, `requested` und `supported`.
- Ohne Header gilt die aktuelle Version.

Jede Antwort im Namensraum `pass-captain/v1` trägt `X-WPCAP-Contract: <vertrag>/<version>`, z. B. `app/1.1`. Welche Schnittstelle gilt, ergibt sich aus der Route: `/partner/…` = `partner`, `/app/offline/…` = `offline`, `/app/…` = `app`, `/developer/events/…` sowie der Eventbericht mit `X-WPCAP-Key` = `developer`, alles andere = `public`.

## Voucher Captain: Gutschein-Anbieter

Pass Captain ruft den Anbieter nur über `PassCaptain\Integrations\Vouchers\Bridge` auf:

- **Version**: Ein Anbieter kann optional die Methode `contract_version()` haben, die z. B. `"1.3"` zurückgibt. Ohne diese Methode gilt `1.0`. Ein Anbieter mit anderer Hauptversion, oder dessen Versionsprüfung eine Ausnahme wirft, wird nie aufgerufen. Gutscheine sind dann ausgeschaltet, und die Statusanzeige unter „Einstellungen“ nennt die inkompatible Version.
- **Pflichtfelder der Antworten**: `quote()` und `reserve()` liefern ein Array; `quote()` braucht `amount_minor` (int, > 0, höchstens der offene Betrag). `currency` (Standard: Währung der Bestellung) und `treatment`/Zweckfelder (Standard: Einstellung „Gutscheinart“) sind optional. `commit()`, `release()` und `refund()` liefern ein Array oder `true`.
- **Fehler**: Eine Ausnahme des Anbieters wird zu `WP_Error` `wpcap_voucher_provider_error` (Status 503). Ein `WP_Error` `http_request_failed` bzw. ein Timeout wird zu `wpcap_voucher_timeout` (504). Eine Antwort außerhalb des Vertrags (z. B. ein String) wird zu `wpcap_voucher_provider_response` (502). 5xx-Fehler gelten als vorübergehend: Commit und Rückbuchung werden mit demselben Idempotenzschlüssel über die Job-Warteschlange wiederholt.
- **Kein halb gebuchter Zustand**: Scheitert `quote()` oder `reserve()`, bleiben Bestellung, Beträge, Steuer und Reservierungen unverändert. Im öffentlichen Checkout wird der Bestellversuch storniert und das Kontingent sofort freigegeben. Bei Timeout oder Ausnahme in `reserve()` ruft Pass Captain `release()` mit `['idempotency_key' => 'wpcap-order-<public_id>', 'order_reference' => 'wpcap:<public_id>']` auf, damit eine eventuell doch angelegte Reservierung frei wird. **Anbieter müssen eine Freigabe über den Idempotenzschlüssel der Reservierung akzeptieren** und eine unbekannte Reservierung als bereits freigegeben behandeln. Scheitert der Commit nach der Zahlung, bleibt die Bestellung bezahlt (Tickets gültig), der Gutschein bleibt `reserved`, und der Commit wird wiederholt. Nach dem letzten Versuch wird die Bestellung als `commit_failed` markiert und der Admin informiert.

## Workflow-Providervertrag v1

Vertrag `workflow` 1.0.0 (A673, `src/Workflow/Contract.php`) für Workflow Captain oder jedes andere Plugin, das
auf Geschäftsereignisse von Pass Captain reagieren will. Der Provider nutzt nur diesen Vertrag – keine
privaten Klassen, keine Tabellen.

- **Registrierung:** Filter `wp_pass_captain_workflow_provider_v1` (Argumente `null`, Hauptversion `1`) liefert ein
  Objekt mit `receive( array $envelope )` und optional `contract_version()` (Standard `1.0.0`). SemVer: jede
  1.x wird angenommen; ein Provider mit anderer Hauptversion, oder dessen Versionsprüfung eine Ausnahme wirft,
  wird nie aufgerufen (`wpcap_workflow_contract_version`). Der letzte Zustand (`missing`, `incompatible`,
  `ready`) steht in der Option `wpcap_workflow_status`.
- **Outbox (A675):** Jedes Ereignis wird nach dem Commit genau einmal in `{prefix}wpcap_event_outbox` geschrieben
  – unabhängig davon, ob Webhooks abonniert sind, das Webhook-Modul aktiv ist oder ein Provider installiert ist.
  Die Ereignis-ID (`evt_…`) entsteht aus Typ und einer stabilen fachlichen Kennung (Bestellung, Ticket,
  Erstattung, Einlasszeile, Revision); feuert ein interner Hook doppelt (Wiederholung, doppelter Webhook des
  Zahlungsanbieters), bleibt es ein Ereignis. Dieselbe ID trägt der ausgehende Webhook desselben Ereignisses.
  Aufbewahrung 30 Tage (`wpcap_workflow_retention_days`).
- **Zustellung:** Cron `wpcap_process_jobs` (und `wpcap_workflow_dispatch` direkt nach einem neuen Ereignis),
  mindestens einmal, in `sequence`-Reihenfolge; ein fehlschlagendes Ereignis hält spätere nicht auf.
  `receive()` antwortet mit `true` oder einem Array (angenommen). `WP_Error` `wpcap_workflow_rejected` oder
  ein `WP_Error` mit `retryable => false` ist endgültig (`failed`); jeder andere `WP_Error`
  (`wpcap_workflow_temporary`) und jede Ausnahme (`wpcap_workflow_provider_error`) wird mit Backoff wie bei
  Webhooks wiederholt, höchstens 8 Versuche (`wpcap_workflow_max_attempts`). Ohne (kompatiblen) Provider wird
  nichts zugestellt und nichts markiert. Nachholen: `PassCaptain\Workflow\Outbox::since( $sequence, $limit,
  $types )` liefert die Envelopes ab einer Sequenznummer (`replay: true`); `Outbox::replay( $sequence )` stellt
  sie erneut zu (Capability `manage_options` = `Contract::CAPABILITY`, Audit `workflow_replay`).
- **Envelope:** `id`, `type`, `contract` (`workflow`), `contract_version`, `schema_version` (je Ereignistyp),
  `occurred_at` (ISO 8601 UTC), `sequence` (aufsteigend), `attempt`, `replay`, `source` (`plugin`, `version`,
  `site_id` bei Multisite), `subject` (`type`, `id`: öffentliche Bestell-/Ticket-ID bzw. Event-/Termin-ID) und
  `data`.
- **Feld-Allowlist:** `data` enthält nur die Felder aus `Contract::OBJECTS`/`Contract::EVENTS` mit den dort
  genannten Typen; alles andere wird verworfen (`wpcap_workflow_field_not_allowed` im Debug-Protokoll), auch
  was der Filter `wpcap_workflow_event_data` ergänzt. **Keine Ticketcodes, Hilfscodes, Zugangstoken, keine
  Namen, E-Mail-Adressen, Telefonnummern, Anschriften oder Antworten aus Teilnehmerfeldern** – unabhängig vom
  Webhook-Opt-in `include_pii`. Ob es für Workflows ein eigenes, protokolliertes PII-Opt-in geben soll, ist eine
  offene Entscheidung (A697).
- **Ereignisse** (alle Schema 1.0): `order.paid`, `order.cancelled`, `order.refunded`, `refund.reversed`
  (Bestellung + `refund`), `ticket.issued` (je Ticket, mit `line_id`), `ticket.revoked` (+ `reason`),
  `ticket.transferred` (+ `source` `portal`/`assign`, `holder_changes`), `ticket.exchanged`
  (+ `previous_ticket_id`, `exchange`), `ticket.checked_in` / `ticket.checked_out` (+ Zeitpunkt, `source`,
  `area_id`, `checkin_id`), `attendance.confirmed` (einmal je Ticket beim ersten gültigen Einlass,
  `method: check_in`), `occurrence.rescheduled` (+ `previous_starts_at`), `occurrence.changed`
  (+ `changed_fields`, nur Feldnamen), `occurrence.cancelled` (+ `issued_tickets`), `event.cancelled`
  (+ `occurrence_ids`).
- **Erstattungen je Bestellung, Zeile und Ticket (A675):** `refund` = `refund_id` (bei Vollerstattung außerhalb
  des Plugins `null`), `order_id`, `kind` (`tickets`: genau diese Tickets; `amount`: Betrag ohne Tickets;
  `full`: ganze Bestellung über WooCommerce oder das Dashboard des Zahlungsanbieters), `amount_minor`,
  `currency`, `source`, `status`, `ticket_ids` (öffentliche IDs) und `lines` (`line_id`, `ticket_type_id`,
  `quantity`). `refund.reversed` ist das Storno einer Erstattung (beim Anbieter nachträglich gescheitert); die
  Tickets bleiben widerrufen.
- **Fehlercodes:** `Contract::ERRORS` (`wpcap_workflow_contract_version`, `…_provider_missing`,
  `…_unknown_event`, `…_field_not_allowed`, `…_provider_error`, `…_forbidden`, `…_temporary`, `…_rejected`).
- **Deprecation:** Ein Feld, das wegfallen soll, steht in `Contract::DEPRECATED` (Ereignistyp.Feld => seit,
  Entfernung, Ersatz), bleibt mindestens zwei Minor-Versionen und sechs Monate erhalten und verschwindet erst
  mit der nächsten Hauptversion (dann neuer Filter `…_v2`). In 1.0.0 ist nichts veraltet.
- **Lizenzen:** Der Kern prüft keine Lizenzen oder Rechte; ob eine Provider-Funktion kostenpflichtig ist,
  entscheidet allein der Provider ([FREE-PREMIUM.md](FREE-PREMIUM.md)).

Fixture: `tests/contracts/workflow.json` (Envelope-Shape je Ereignistyp); `tests/integration/modules/zz_a673_a675.php`
prüft echte Abläufe dagegen.

## Workflow-Commands v1

`PassCaptain\Workflow\Commands::execute( $command, array $args, $idempotency_key )` (A676, `src/Workflow/Commands.php`)
lässt einen Workflow-Provider ausgewählte Aktionen ausführen – nur diese, über die regulären Dienste des Kerns (mit
deren Prüfungen, Benachrichtigungen und Ereignissen). Zahlung, Erstattung, Ticketausgabe und Ticketcodes haben keinen
Command.

| Command | Capability | Argumente (Pflicht fett) | Dienst |
|---|---|---|---|
| `mail.resend` | `wpcap_manage_orders` | **`order_id`** (öffentliche ID), `new_codes` (bool, Standard: gespeicherte Codes erneut senden) | `Orders::resend_tickets()` (übertragene Tickets behalten ihren Code) |
| `ticket.revoke` | `wpcap_manage_orders` | **`ticket_id`** (öffentliche ID), **`reason`** | „Ticket widerrufen“ im Admin (ohne Erstattung) |
| `event.cancel` | `wpcap_manage_events` | **`event_id`**, **`reason`** | reguläre Eventabsage (inkl. der eingestellten automatischen Erstattung `auto_refund_cancelled`) |
| `occurrence.reschedule` | `wpcap_manage_events` | **`occurrence_id`**, **`starts_at`** (ISO 8601 mit Zeitzone), `ends_at` | Terminformular (Revision, Benachrichtigung, Verkaufsfenster; Ende und Einlass verschieben sich mit) |
| `attendance.confirm` | `wpcap_checkin` | **`ticket_id`**, `method` (`manual`, `list`, `session`, `online`) | zulässige Teilnahmebestätigung ohne Scan: nur bezahlte, nicht erstattete, gültige Tickets, ab Terminbeginn; Aktion `wpcap_attendance_confirmed`, Ereignisse `attendance.confirmed` (Workflow) und `experience_completed` (Review, `method` `confirmed`) |

- **Allowlist:** unbekannte Commands (`wpcap_workflow_command_unknown`) und Argumente
  (`wpcap_workflow_command_arg_not_allowed`, `arg`) werden abgelehnt, fehlende Pflichtargumente ebenso
  (`wpcap_workflow_command_invalid`).
- **Capability:** der aktuelle Benutzer (der Provider handelt als Benutzer) braucht die Capability des Commands
  (`wpcap_workflow_forbidden`, 403); zusätzlich gelten die Event-Rechte des jeweiligen Dienstes.
- **Idempotency-Key** (Pflicht, 8–64 Zeichen `[A-Za-z0-9._:-]`, Tabelle `wpcap_workflow_commands`, Schema 3.10.27):
  derselbe Key mit demselben Command und denselben Argumenten liefert das gespeicherte Ergebnis (`replayed` = true)
  bzw. denselben Fehler, ohne erneut auszuführen; derselbe Key mit anderen Argumenten ist ein Konflikt
  (`wpcap_workflow_idempotency_conflict`, 409); läuft er noch: `wpcap_workflow_command_in_progress`. Vorübergehende
  Fehler (5xx) verbrauchen den Key nicht.
- **Audit:** jeder Aufruf als `workflow_command` (Command, Hash des Keys, Ergebnis) – ohne Personendaten. Ergebnisse
  enthalten nie Ticketcodes, Hilfscodes oder Personendaten.

### Optionale Mails an den Workflow-Provider übergeben (A677)

Optionale Mails kann die Betreiber:in je Mailtyp an den Workflow-Provider übergeben (`PassCaptain\Workflow\MailHandoff`,
Auswahl unter **Pass Captain → Systemstatus → Betrieb → Captain-Suite**, Option `wpcap_workflow_mail_handoff`, Recht `manage_options`,
Audit `workflow_mail_handoff_set`). Übergebbar ist im Kern die **Terminerinnerung** (`queue_wpcap_reminder`, Modul
Erinnerungen); Add-ons können weitere optionale Typen über `wpcap_workflow_mail_handoff_types` anbieten. Ticket-,
Zahlungs-, Reservierungs-, Erstattungs-, Absage-/Änderungs-, Übertragungs-, Wartelistenangebots- und Einlassmails
(`MailHandoff::NEVER`) bleiben immer bei Pass Captain – auch wenn ein Filter oder die Option sie nennt.

- **Provider:** der registrierte Workflow-Provider (kompatible Hauptversion) mit
  `handle_mail( array $handoff ): true|array|WP_Error`. `$handoff`: `handoff_version` (1.0.0), `type`, `to`, `locale`,
  `subject`, `body`, `format` (`text`|`html`), `order_id` (öffentliche ID oder leer). Das ist die ausdrücklich gewählte
  Übergabe dieser Mail – sie enthält deshalb Empfänger und Inhalt; Mails mit Anhang werden nie übergeben.
- **Nativer Rückfall:** ohne (kompatiblen) Provider, ohne `handle_mail()`, bei einer Ausnahme oder einer Ablehnung
  versendet Pass Captain die Mail selbst wie bisher. Jede Entscheidung steht im Support-Protokoll (Kanal `mail`, ohne
  Adresse) und wird gezählt (Option `wpcap_workflow_mail_handoff_stats`: angenommen, Rückfälle, letzte Entscheidung mit
  Grund `provider_missing`, `provider_incompatible`, `not_supported`, `rejected`, `provider_error`, `attachments`).

### End-to-End-Prüfung mit einem Stub-Workflow-Plugin (A679)

`tests/integration/modules/zz_a679.php` spielt den Vertrag mit einem Stub-Plugin durch: Workflow fehlt (Kernabläufe
laufen, Ereignisse warten), andere Hauptversion (nie aufgerufen), später installiert (alles kommt einmal und in
Reihenfolge an, `attempt` 2 nach vorübergehendem Fehler), PII-Redaction auch für Felder, die ein Filter einschleust,
Duplikate (wiederholte Hooks), Replay mit Capability und `replay` = true, Pull über `since()`, Ablehnung durch das
Plugin (z. B. dessen eigenes Pro-Recht fehlt oder ist abgelaufen: endgültig, Kern unberührt), deaktiviertes Plugin,
Multisite (`source.site_id`) und die Commands gegen `tests/contracts/workflow_commands.json`. HPOS und klassische
WooCommerce-Speicherung sind in der Testlaufzeit nicht unterscheidbar; die Outbox sieht nur Kernbestellungen.

## Review-Teilnahmevertrag v1

Vertrag `review` 1.0.0 (A667, `src/Review/Contract.php`, gemeinsame Mechanik in `src/Suite/Contract.php`) für Review
Captain oder jedes andere Plugin, das nach einer **tatsächlichen Teilnahme** um eine Bewertung bitten will. Pass
Captain ist Quelle für Event, Ticket, Absage, Erstattung und Einlass; Einladung, Frequenzlimit, Opt-out, Versand und
Bewertungsdaten gehören dem Provider. Der Kern bleibt ohne Provider unverändert und prüft keine Lizenzen.

- **Registrierung:** Filter `wp_pass_captain_review_provider_v1` (Argumente `null`, Hauptversion `1`) liefert ein Objekt
  mit `receive( array $envelope )` und optional `contract_version()`; eine andere Hauptversion wird nie aufgerufen
  (`wpcap_review_contract_version`). Letzter Zustand in der Option `wpcap_review_status`.
- **Zustellung:** dieselbe Ereignis-Outbox wie beim Workflow-Vertrag (Spalte `contract` = `review`, Schema 3.10.27),
  eigene Zustellung an den Review-Provider mit Reihenfolge, Backoff, höchstens 8 Versuchen und Aufbewahrung 30 Tage;
  Antworten und Fehlercodes wie beim Workflow-Vertrag mit Präfix `wpcap_review_`. Nachholen:
  `Outbox::since( $sequence, $limit, array(), 'review' )`, erneut zustellen: `Outbox::replay( $sequence, 'review' )`.
- **Ereignisse** (Schema-Version je Typ 1.0):
  - `experience_completed` – einmal je Teilnahme beim **ersten gültigen Einlass** (`wpcap_ticket_checked_in`) oder bei
    einer ausdrücklich zugelassenen Teilnahmebestätigung (`wpcap_attendance_confirmed`, Command `attendance.confirm`),
    nie bei Kauf, Ticketversand oder bloßer Einlösung; nur für bezahlte, nicht erstattete Tickets nicht abgesagter
    Termine/Events. Daten: `participation_id` (öffentliche Ticket-ID), `experience_id` (`occurrence:<id>`), `event_id`,
    `occurrence_id`, `ticket_type_id`, `recipient_ref`, `locale` (Sprache der Bestellung), `outcome` `attended`,
    `completed_at`, `method` (`check_in`|`confirmed`), `source`. Erst nach einer Rücknahme des Einlasses kann eine
    Teilnahme erneut abgeschlossen werden (neuer Zyklus, neue Ereignis-ID).
  - `experience_excluded` (A669) – Korrektur je Teilnahme, `reason`: `check_in_undone` (kein gültiger Einlass mehr;
    Aktion `wpcap_checkin_undone`), `refunded`, `revoked` (auch Storno), `transferred` (mit
    `previous_recipient_ref` = Empfängerreferenz des letzten Abschlusses) und `no_show` (Cron: gültige Eventtickets
    ohne Einlass 6 h nach Terminende, Filter `wpcap_review_no_show_grace_hours`, für Termine der letzten 14 Tage).
  - `experience_cancelled` (A669) – abgesagter Termin bzw. abgesagtes Event **einmal auf Erlebnisebene**
    (`scope` `occurrence`|`event`, `experience_id` `occurrence:<id>` bzw. `event:<id>`, `occurrence_ids`) statt je Ticket.
- **Idempotenz:** Ereignis-IDs sind stabil (Typ + Teilnahme + Grund/Zyklus); wiederholte Hooks, Mehrfacheinlässe und
  wiederholte Erstattungs-Webhooks erzeugen nichts Neues. „Höchstens eine Anfrage je Erlebnis und Empfänger“ sichert
  der Provider mit `experience_id` + `recipient_ref`.
- **Keine Personendaten:** keine E-Mail, kein Name, kein Ticket- oder Hilfscode. `recipient_ref` (`rcp_…`) ist eine
  undurchsichtige Referenz der aktuellen Inhaberin bzw. des aktuellen Inhabers und ändert sich mit jedem
  Inhaberwechsel.

- **Kontakt-Resolver (A668):** `PassCaptain\Review\ContactResolver::resolve( $participation_id, $recipient_ref,
  array( 'source' => 'review-captain' ) )` liefert `email`, `name` und `locale` der aktuellen Inhaberin bzw. des
  aktuellen Inhabers – nur mit der eigenen Capability `wpcap_resolve_review_contacts` (`Contract::RESOLVER_CAPABILITY`;
  sie gehört zu keiner Plugin-Rolle und wird Administrator:innen nicht automatisch gegeben, die Betreiber:in vergibt sie
  bewusst, z. B. an das Dienstkonto des Review-Plugins), nur für abgeschlossene Erlebnisse im aktuellen Zyklus
  (bezahlt, nicht erstattet, nicht widerrufen, Termin/Event nicht abgesagt) und nur mit der aktuellen
  `recipient_ref`. Fehler: `wpcap_review_forbidden` (403), `wpcap_review_not_found` (404),
  `wpcap_review_not_eligible` (409, `reason`: `not_completed`, `refunded`, `revoked`, `cancelled`, `unpaid`,
  `no_contact`), `wpcap_review_recipient_changed` (409). Jeder Aufruf wird als `review_contact_resolved` mit
  Teilnahme, Zweck, Quelle und Ergebnis protokolliert – nie mit der Adresse. Adresse: zugewiesene
  Teilnehmer-E-Mail, sonst Ticket-E-Mail, sonst Käufer-E-Mail (Platzhalteradressen nie).

Fixture: `tests/contracts/review.json`; `tests/integration/modules/zz_a667_a669.php` prüft echte Abläufe dagegen.

**E2E-Matrix (A671):** `tests/integration/modules/zz_a671.php` spielt Ticketkauf → Einlass → Bewertungsanfrage mit
einem Stub-Review-Plugin durch (Plugin fehlt zunächst und holt nach, Kontakt über den Resolver, eigene Opt-out-Liste,
„eine Anfrage je Erlebnis und Empfänger“): eingelassen → genau eine Anfrage; doppelte Scans, wiederholte Hooks und
Replay → weiterhin eine; Erstattung vor oder nach dem Einlass, abgesagter Termin, No-show und Opt-out → keine;
Übertragung vor dem Einlass → Anfrage an die neue Inhaberin bzw. den neuen Inhaber. Kauf, Ticketversand und andere
Ereignisse erreichen das Review-Plugin nicht.

## Fixtures und Tests

Die Verträge liegen als JSON unter `tests/contracts/` (eine Datei je Schnittstelle: `public`, `partner`, `developer`, `app`, `offline`, `voucher-bridge`, `affiliate`, `workflow`, `workflow_commands`, `review`). Jeder Eintrag beschreibt einen Endpunkt bzw. Aufruf:

- `direction: inbound` (andere rufen Pass Captain auf): `request` ist ein Beispiel mit Platzhaltern `{{name}}`, `response` ist die Shape der Antwort.
- `direction: outbound` (Pass Captain ruft Voucher Captain oder Affiliate Captain auf): `request` ist die Shape dessen, was Pass Captain sendet, `response` ist ein Beispiel der kleinsten gültigen Antwort der Gegenseite.

Eine Shape ist JSON. Ein String ist ein Typ (`int`, `float`, `number`, `string`, `bool`, `null`, `array`, `object`, `any`, Vereinigungen mit `|`, z. B. `string|null`). `[shape]` ist eine Liste, `{…}` ein Objekt. Ein Feld mit `?` vor dem Namen ist optional, `*` gilt für alle weiteren Schlüssel (Maps). Zusätzliche Felder sind immer erlaubt.

`tests/integration/modules/zz_a203.php` ruft die echten Handler mit den Beispiel-Requests auf und vergleicht die Antworten (bzw. die gesendeten Payloads) mit den Shapes. Der Test schlägt fehl, wenn ein Feld fehlt, umbenannt wurde oder seinen Typ ändert, oder wenn ein Request-Parameter aus dem Beispiel nicht mehr verstanden wird. Außerdem prüft er unbekannte Zusatzfelder, fehlende optionale Felder, die Versionsprüfung sowie Ausnahmen, Timeouts und fehlerhafte Antworten der Gegenseite.

Neue Felder fügt man der Fixture hinzu (optional mit `?`, wenn sie nicht immer vorkommen). Für einen neuen Endpunkt legt man den Eintrag mit `request` an und startet die Tests einmal mit `WPCAP_CONTRACTS_RECORD=1`. Die fehlende Shape wird dann aus der echten Antwort geschrieben und muss von Hand geprüft werden (nullable Felder, optionale Felder, leere Listen). Ein Feld aus der Fixture zu entfernen oder seinen Typ zu ändern ist ein Bruch des Vertrags und nur mit einer neuen Hauptversion zulässig.
