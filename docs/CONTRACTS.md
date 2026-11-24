# Schnittstellenverträge und Versionierung

Pass Captain bietet mehrere Schnittstellen, auf die sich andere Programme verlassen: die öffentliche REST-API, die Partner-API, die Entwickler-API, die Mobile-App-API, den Offline-Scanner, die Brücke zu Voucher Captain, den Adapter für Affiliate Captain, den Review-Teilnahmevertrag und die öffentlichen Hooks für Add-ons. Für jede Schnittstelle gilt dieselbe Versionsregel. Die Prüfung steht in `src/Contracts.php`.

## Versionsregel

Jede Schnittstelle hat eine Vertragsversion `MAJOR.MINOR`:

- **Minor-Version** (z. B. 1.0 → 1.1): Es kommen nur Felder, Endpunkte oder optionale Parameter hinzu. Ein bestehendes Feld wird nicht entfernt, nicht umbenannt und ändert seinen Typ nicht (`int` bleibt `int`, ein Objekt wird keine Liste, ein Pflichtfeld wird nicht optional). Optionale Felder dürfen fehlen, wenn sie dokumentiert optional sind.
- **Major-Version** (z. B. 1.x → 2.0): Nur hier sind inkompatible Änderungen erlaubt. Eine neue Hauptversion bekommt einen neuen Filter- bzw. Namensraum (z. B. `wp_pass_captain_voucher_provider_v2`). Die alte Version läuft mindestens eine Minor-Version des Plugins parallel und wird in `CHANGELOG.md` angekündigt.
- **Gegenseite**: Clients und Anbieter müssen unbekannte zusätzliche Felder ignorieren. Pass Captain tut das ebenfalls: Unbekannte Felder in Requests, in Antworten von Voucher Captain oder von Affiliate Captain werden ignoriert (bei Voucher Captain unverändert gespeichert und zurückgegeben, aber nie ausgewertet).

| Schnittstelle | Vertrag | Version | Dokumentation |
|---|---|---|---|
| Öffentliche REST-API (`pass-captain/v1`: Events, Checkout, Bestellportal, …) | `public` | 1.3 (1.1: `/events` mit Zeiten in ISO 8601 mit Offset, Phase, Revision und Verkaufsstatus je Ticketart; Checkout-Positionen mit optionalem `occurrence_revision`/`expected_price_minor`, siehe [OPERATIONS.md](OPERATIONS.md#checkout-an-den-angezeigten-stand-gebunden-a560); 1.2: `/events` je Ticketart `service_fee_minor`, `display_price_minor` und `order_fee_minor`, siehe [OPERATIONS.md](OPERATIONS.md#vorverkaufs-servicegebühr-a425); 1.3: neuer Endpunkt `POST /orders/link-request` (Bestelllinks per E-Mail anfordern, gleiche Antwort für jede Adresse) und optionaler Checkout-Parameter `form_guard` (`{hp, ms}`, nur vom eigenen Formular gesendet; Ablehnung mit 400 `wpcap_form_guard` bzw. `wpcap_form_too_fast`), siehe [SECURITY.md](SECURITY.md#bestelllinks-anfordern-und-bot-prüfung-im-checkout)) | [SECURITY.md](SECURITY.md) |
| Partner-API (`/partner/…`) | `partner` | 1.0 (Header `X-WPCAP-Partner-API: 1`) | [PARTNER-API.md](PARTNER-API.md) |
| Entwickler-API (`X-WPCAP-Key`) | `developer` | 1.0 | [SECURITY.md](SECURITY.md) |
| Mobile-App-API (`/app/…`) | `app` | 1.2 (`api_version` in `/app/info` und `/app/me`; 1.1: Check-out, Bereiche, erweiterte Statistik und Ergebnis-Codes; 1.2: `/app/info`, Sammelaktionen `/app/scan/batch`, Versions-Header, `permissions.check_out` in `/app/me`) | [MOBILE-API.md](MOBILE-API.md) |
| Offline-Scanner (`/app/offline/…`, Paketformat `v`) | `offline` | 1.1 (Hauptversion = Paketformat `v` 1, die zweite Stelle zählt `PassCaptain\OfflineScanner\Package::CONTRACT_MINOR`; 1.1: im Paket `ticket_types[].own_policy`, `max_entries`, `valid_days`, `day_cutoff_hour`, `permissions` (`undo`, `check_out`) und `checkout` (`staff_area_ids`, `reentry_after_checkout`, `max_reentries`), dazu der Upload offline erfasster Check-outs über `/app/scan/sync` mit `direction` `out`; das Paketformat `v` bleibt 1 bzw. 2) | [OFFLINE-SCANNER.md](OFFLINE-SCANNER.md) |
| Gutschein-Anbieter (`wp_pass_captain_voucher_provider_v1`) | `voucher_provider` | 1.0 | [VOUCHERS.md](VOUCHERS.md) |
| Ticketdienst und Check-in-Dienst für Voucher Captain (`wp_pass_captain_ticket_service_v1`, `wp_pass_captain_checkin_service_v1`) | `ticket_service`, `checkin_service` | 1.0 | [VOUCHERS.md](VOUCHERS.md) |
| Affiliate Captain (`wpac_record_sale()`, `wpac_record_refund()`) | `affiliate` | 1.0.0 | [EXTENSIONS.md](EXTENSIONS.md) |
| Ausgehende Webhooks (signierte POSTs, [WEBHOOKS.md](WEBHOOKS.md)) | `webhooks` | `api_version` 2026-11-01, `schema_version` je Ereignistyp (1.0; `order.refunded` 1.1) | [WEBHOOKS.md](WEBHOOKS.md) |
| Öffentliche Aktionsfunktionen (`wpcap_resend_ticket_mail()`, `wpcap_revoke_ticket()`, `wpcap_cancel_event()`, `wpcap_reschedule_occurrence()`, `wpcap_confirm_attendance()`) | `actions` | 1.0.0 (`PassCaptain\Api\Actions::VERSION`; `WPCAP_CONTRACT_VERSION` 1.4.0) | [unten](#öffentliche-aktionsfunktionen-pc-7) |
| Review-Provider (`wp_pass_captain_review_provider_v1`, Teilnahmevertrag `experience_completed`) | `review` | 1.0.0 (`PassCaptain\Review\Contract::VERSION`) | [unten](#review-teilnahmevertrag-v1) |
| Öffentliche Add-on-API (Hooks für `pass-captain-pro` und andere Plugins, `wpcap_loaded`) | `addon` | 1.0.0 (`PassCaptain\PublicApi::VERSION`) | [HOOKS.md](HOOKS.md#öffentliche-add-on-api) |
| Lesefunktion Ticket → Bestellung, Event, Termin (`wpcap_get_ticket()`) | `ticket_read` | 1.0.0 (`PassCaptain\Api\Tickets::VERSION`) | [unten](#ticket-lesevertrag-v1) |
| Connectoren (`wp_pass_captain_connectors_v1`, `ConnectorInterface`, Ereignisse über die Job-Queue; Andockpunkt der Pro-Connectoren BoatingSchool/LearnBoating) | `connector` | 1.2.0 (`PassCaptain\Integrations\ConnectorEvents::CONTRACT_VERSION`, Payload `contract_version`; 1.1.0: `voucher_redeemed`, `ticket_revoked`, Grund als Code; 1.2.0: `ticket_checked_out`, `event_cancelled`, `attendance_confirmed`, `ticket_transferred` – nur neue Ereignisse, daher Minor) | [EXTENSIONS.md](EXTENSIONS.md#connectoren) |

## REST: Versionsangabe des Clients

Ein Client kann die Version, gegen die er gebaut ist, im Header `X-WPCAP-Contract` mitschicken (`1.0`, `1.4`, `1`):

- Gleiche Hauptversion (jede Minor-Version, auch eine neuere als die des Servers): Die Anfrage läuft normal.
- Andere Hauptversion oder kein gültiger Versionswert: Antwort `400` mit Code `wpcap_contract_version`, bevor Berechtigungsprüfung oder Handler laufen. `data` enthält `api`, `requested` und `supported`.
- Ohne Header gilt die aktuelle Version.

Jede Antwort im Namensraum `pass-captain/v1` trägt `X-WPCAP-Contract: <vertrag>/<version>`, z. B. `app/1.2`. Welche Schnittstelle gilt, ergibt sich aus der Route: `/partner/…` = `partner`, `/app/offline/…` = `offline`, `/app/…` = `app`, `/developer/events/…` sowie der Eventbericht mit `X-WPCAP-Key` = `developer`, alles andere = `public`.

## Voucher Captain: Gutschein-Anbieter

Pass Captain ruft den Anbieter nur über `PassCaptain\Integrations\Vouchers\Bridge` auf:

- **Version**: Ein Anbieter kann optional die Methode `contract_version()` haben, die z. B. `"1.3"` zurückgibt. Ohne diese Methode gilt `1.0`. Ein Anbieter mit anderer Hauptversion, oder dessen Versionsprüfung eine Ausnahme wirft, wird nie aufgerufen. Gutscheine sind dann ausgeschaltet, und die Statusanzeige unter „Einstellungen“ nennt die inkompatible Version.
- **Pflichtfelder der Antworten**: `quote()` und `reserve()` liefern ein Array; `quote()` braucht `amount_minor` (int, > 0, höchstens der offene Betrag). `currency` (Standard: Währung der Bestellung) und `treatment`/Zweckfelder (Standard: Einstellung „Gutscheinart“) sind optional. `commit()`, `release()` und `refund()` liefern ein Array oder `true`.
- **Fehler**: Eine Ausnahme des Anbieters wird zu `WP_Error` `wpcap_voucher_provider_error` (Status 503). Ein `WP_Error` `http_request_failed` bzw. ein Timeout wird zu `wpcap_voucher_timeout` (504). Eine Antwort außerhalb des Vertrags (z. B. ein String) wird zu `wpcap_voucher_provider_response` (502). 5xx-Fehler gelten als vorübergehend: Commit und Rückbuchung werden mit demselben Idempotenzschlüssel über die Job-Warteschlange wiederholt.
- **Kein halb gebuchter Zustand**: Scheitert `quote()` oder `reserve()`, bleiben Bestellung, Beträge, Steuer und Reservierungen unverändert. Im öffentlichen Checkout wird der Bestellversuch storniert und das Kontingent sofort freigegeben. Bei Timeout oder Ausnahme in `reserve()` ruft Pass Captain `release()` mit `['idempotency_key' => 'wpcap-order-<public_id>', 'order_reference' => 'wpcap:<public_id>']` auf, damit eine eventuell doch angelegte Reservierung frei wird. **Anbieter müssen eine Freigabe über den Idempotenzschlüssel der Reservierung akzeptieren** und eine unbekannte Reservierung als bereits freigegeben behandeln. Scheitert der Commit nach der Zahlung, bleibt die Bestellung bezahlt (Tickets gültig), der Gutschein bleibt `reserved`, und der Commit wird wiederholt. Nach dem letzten Versuch wird die Bestellung als `commit_failed` markiert und der Admin informiert.

## Kein Workflow-Vertrag im Kern (PC-7)

Entscheidung der Inhaberin vom 23.11.2026 nach den Suite-Entscheidungen D13 und D10 (F-12): Der Kern hat **keinen
eigenen Workflow-Vertrag, keine Ereignis-Outbox für Workflows und keine Übergabe von Mails** an ein anderes Plugin.
Workflow Captain (und jedes andere Automations-Plugin) nutzt nur

- die `wpcap_*`-Actions ([HOOKS.md](HOOKS.md), Zeitpunkte im [Suite-Katalog](HOOKS.md#suite-katalog-seit-welcher-version-zeitpunkt-grund)),
- die Lesefassade `wpcap_get_*_state()` ([EXTENSIONS.md](EXTENSIONS.md#lesefassade-für-zustände)) und
- die [öffentlichen Aktionsfunktionen](#öffentliche-aktionsfunktionen-pc-7) `wpcap_resend_ticket_mail()`,
  `wpcap_revoke_ticket()`, `wpcap_cancel_event()`, `wpcap_reschedule_occurrence()` und `wpcap_confirm_attendance()`.

Umschlag, Outbox, Wiederholung und Befehlswarteschlange liegen beim Abnehmer (Workflow Captain und der Adapter in
Workflow Captain Pro). Bis Plugin 1.6.0 enthielt der Kern einen Vertrag `workflow` 1.0.0
(`wp_pass_captain_workflow_provider_v1`, Klassen `PassCaptain\Workflow\*`, Mail-Übergabe
`wpcap_workflow_mail_handoff`); kein Suite-Plugin nutzt ihn (der `PassAdapter` von Workflow Captain Pro arbeitet nur
mit den Actions und der Lesefassade). Was davon bleibt:

- Die Tabelle `{prefix}wpcap_event_outbox` bedient nur noch den [Review-Teilnahmevertrag](#review-teilnahmevertrag-v1)
  (`PassCaptain\Suite\Outbox`). Zeilen mit `contract` = `workflow` aus älteren Versionen werden nie zugestellt und
  nach der Aufbewahrungsfrist (30 Tage) gelöscht. Die Spaltenvorgabe `contract` = `workflow` bleibt historisch
  stehen (kein Schemawechsel); jede neue Zeile nennt ihren Vertrag.
- Die Optionen `wpcap_workflow_status`, `wpcap_workflow_mail_handoff` und `wpcap_workflow_mail_handoff_stats` werden
  nicht mehr gelesen; die Deinstallation entfernt sie mit allen `wpcap_*`-Optionen.
- Die Ereignis-IDs der ausgehenden Webhooks (`evt_…` aus Typ und fachlicher Kennung) bleiben unverändert.

Wächtertest (wie bei Voucher und Affiliate Captain): `tests/integration/modules/zz_pc7_guard.php` schlägt fehl, sobald
`src/` wieder einen Workflow-Provider-Filter (`wp_pass_captain_workflow_provider_v*`), Klassen im Namensraum
`PassCaptain\Workflow`, eine Mail-Übergabe (`wpcap_workflow_mail_handoff`, `handle_mail`) oder `wpcap_workflow_*`-Hooks
enthält, und prüft, dass `Mailer::deliver()` eine Mail auch dann selbst versendet, wenn ein Plugin anbietet, sie zu
übernehmen.

## Öffentliche Aktionsfunktionen (PC-7)

Fünf schlanke PHP-Funktionen (`src/functions.php`, Klasse `PassCaptain\Api\Actions`, Vertrag `actions` 1.0.0, Teil von
`WPCAP_CONTRACT_VERSION` 1.4.0) lassen ein Plugin auf derselben Website – z. B. Workflow Captain aus seinen eigenen
Abläufen – ausgewählte Aktionen ausführen. Sie rufen die regulären Dienste des Kerns direkt auf (mit deren Prüfungen,
Benachrichtigungen, Hooks und Audit-Einträgen); es gibt **keine Warteschlange und keinen Umschlag**. Zahlung,
Erstattung, Ticketausgabe und Ticketcodes haben keine Aktion. Sie ersetzen die Workflow-Commands (A676, bis 1.6.0).

| Funktion | Capability | Dienst | Ergebnis (zusätzlich `action`, `replayed`) |
|---|---|---|---|
| `wpcap_resend_ticket_mail( int\|string $order, array $args = array() )` | `wpcap_manage_orders` (+ Zugriff auf die Bestellung) | `Orders::resend_tickets()`: Standard die gespeicherten Codes, `$args['new_codes'] = true` neue Codes; übertragene Tickets behalten ihren Code | `order_id` (öffentliche ID), `status` `sent`, `codes` `kept`\|`new` |
| `wpcap_revoke_ticket( int\|string $ticket, string $reason, array $args = array() )` | `wpcap_manage_orders` (+ Event-Recht) | „Ticket widerrufen“ im Admin (ohne Erstattung); Grund Pflicht | `ticket_id` (öffentliche ID), `status` `revoked` |
| `wpcap_cancel_event( int $event_id, string $reason, array $args = array() )` | `wpcap_manage_events` (+ Event-Recht) | reguläre Eventabsage (inkl. der eingestellten automatischen Erstattung `auto_refund_cancelled`); ein schon abgesagtes Event setzt seinen Absagelauf fort | `event_id`, `status` `cancelled`, `run_status`, `complete` (Absagelauf fertig) |
| `wpcap_reschedule_occurrence( int $occurrence_id, string $starts_at, ?string $ends_at = null, array $args = array() )` | `wpcap_manage_events` (+ Event-Recht) | Terminformular (Revision, Benachrichtigung, Verkaufsfenster); ISO 8601 mit Zeitzone; ohne `$ends_at` verschieben sich Ende und Einlass mit | `occurrence_id`, `status` `rescheduled`, `starts_at` (UTC, ISO 8601) |
| `wpcap_confirm_attendance( int\|string $ticket, string $method = 'manual', array $args = array() )` | `wpcap_checkin` (+ Einlassrecht für das Event) | zulässige Teilnahmebestätigung ohne Scan (`method`: `manual`, `list`, `session`, `online`): nur bezahlte, nicht erstattete, gültige Tickets eines nicht abgesagten Termins ab Terminbeginn; Audit `attendance_confirmed`, Action `wpcap_attendance_confirmed` (`source` `api`), im Review-Vertrag `experience_completed` mit `method` `confirmed` (einmal je Ticket) | `ticket_id`, `status` `confirmed`, `method` |

- **IDs:** Bestellung und Ticket als interne ID (int oder Ziffernfolge) oder öffentliche ID (32 Hex-Zeichen); Event und
  Termin als interne ID. Unbekannt: `wpcap_action_not_found` (404).
- **Optionen `$args`:** `user_id` (die Aktion läuft als dieser Benutzer: dessen Capability wird geprüft, der aktuelle
  Benutzer wird danach wiederhergestellt; ohne `user_id` gilt der aktuelle Benutzer), `idempotency_key`, bei
  `wpcap_resend_ticket_mail()` zusätzlich `new_codes`. Unbekannte Optionen und ungültige Argumente werden abgelehnt,
  nie ignoriert (`wpcap_action_invalid`, 400, `arg`).
- **Capability:** fehlt sie, passiert nichts (`wpcap_action_forbidden`, 403); zusätzlich gelten die Event-Rechte des
  jeweiligen Dienstes (deren Fehlercodes werden mit HTTP-Status in `data.status` durchgereicht).
- **Idempotenz:** ohne `idempotency_key` läuft jede Aktion erneut (die Dienste selbst sind, wo es geht, idempotent:
  ein widerrufenes Ticket wird nicht noch einmal widerrufen, eine Teilnahme zählt im Review-Vertrag einmal). Mit Key
  (8–64 Zeichen `[A-Za-z0-9._:-]`, sonst `wpcap_action_idempotency_key`) liefert derselbe Key mit derselben Aktion
  und denselben Argumenten das gespeicherte Ergebnis (`replayed` = true) bzw. denselben Fehler, ohne erneut
  auszuführen; derselbe Key mit anderen Argumenten ist ein Konflikt (`wpcap_action_idempotency_conflict`, 409);
  läuft er noch: `wpcap_action_in_progress` (409). Vorübergehende Fehler (5xx, z. B. `wpcap_action_unavailable`)
  verbrauchen den Key nicht. Gespeichert in `{prefix}wpcap_workflow_commands` (Tabellenname historisch, Schema 3.10.27).
- **Audit:** jeder Aufruf nach der Argumentprüfung als `api_action` (Aktion, Objekt-ID, Benutzer, Hash des Keys,
  Ergebnis) – ohne Personendaten. Ergebnisse enthalten nie Ticketcodes, Hilfscodes oder Personendaten.

Fixture: `tests/contracts/actions.json`; Test `tests/integration/modules/zz_pc7_actions.php`.

## Review-Teilnahmevertrag v1

Vertrag `review` 1.0.0 (A667, `src/Review/Contract.php`, gemeinsame Mechanik in `src/Suite/Contract.php`) für Review
Captain oder jedes andere Plugin, das nach einer **tatsächlichen Teilnahme** um eine Bewertung bitten will. Pass
Captain ist Quelle für Event, Ticket, Absage, Erstattung und Einlass; Einladung, Frequenzlimit, Opt-out, Versand und
Bewertungsdaten gehören dem Provider. Der Kern bleibt ohne Provider unverändert und prüft keine Lizenzen.

- **Registrierung:** Filter `wp_pass_captain_review_provider_v1` (Argumente `null`, Hauptversion `1`) liefert ein Objekt
  mit `receive( array $envelope )` und optional `contract_version()`; eine andere Hauptversion wird nie aufgerufen
  (`wpcap_review_contract_version`). Letzter Zustand in der Option `wpcap_review_status`.
- **Outbox und Zustellung (A675):** Jedes Ereignis wird nach dem Commit genau einmal in `{prefix}wpcap_event_outbox`
  geschrieben (`PassCaptain\Suite\Outbox`, Spalte `contract` = `review`, Schema 3.10.27) – auch ohne Provider. Die
  Ereignis-ID (`evt_…`) entsteht aus Typ und einer stabilen fachlichen Kennung; ein doppelt feuernder Hook bleibt ein
  Ereignis. Zustellung über Cron `wpcap_process_jobs` (und `wpcap_outbox_dispatch` direkt nach einem neuen
  Ereignis), mindestens einmal, in `sequence`-Reihenfolge; ein fehlschlagendes Ereignis hält spätere nicht auf.
  `receive()` antwortet mit `true` oder einem Array (angenommen). `WP_Error` `wpcap_review_rejected` oder ein
  `WP_Error` mit `retryable => false` ist endgültig (`failed`); jeder andere `WP_Error` (`wpcap_review_temporary`)
  und jede Ausnahme (`wpcap_review_provider_error`) wird mit Backoff wie bei Webhooks wiederholt, höchstens 8 Versuche
  (`wpcap_outbox_max_attempts`). Ohne (kompatiblen) Provider wird nichts zugestellt und nichts markiert; Aufbewahrung
  30 Tage (`wpcap_outbox_retention_days`). Nachholen: `Outbox::since( $sequence, $limit, $types, 'review' )`
  (Envelopes mit `replay: true`), erneut zustellen: `Outbox::replay( $sequence, 'review' )` (Capability
  `manage_options` = `Contract::CAPABILITY`, Audit `review_replay`).
- **Envelope:** `id`, `type`, `contract` (`review`), `contract_version`, `schema_version` (je Ereignistyp),
  `occurred_at` (ISO 8601 UTC), `sequence` (aufsteigend), `attempt`, `replay`, `source` (`plugin`, `version`,
  `site_id` bei Multisite), `subject` (`type`, `id`) und `data` (nur die Felder aus `Contract::OBJECTS`/`EVENTS`;
  alles andere wird verworfen, auch was der Filter `wpcap_outbox_event_data` ergänzt).
- **Ereignisse** (Schema-Version je Typ 1.0):
  - `experience_completed` – einmal je Teilnahme beim **ersten gültigen Einlass** (`wpcap_ticket_checked_in`) oder bei
    einer ausdrücklich zugelassenen Teilnahmebestätigung (`wpcap_attendance_confirmed`, `wpcap_confirm_attendance()`),
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

## Ticket-Lesevertrag v1

Vertrag `ticket_read` 1.0.0 (A709, `src/Api/Tickets.php`) für Suite-Plugins (z. B. Review Captain Pro), die zu einem
Ticket Bestellung, Event und Termin brauchen, ohne Datenbanktabellen zu lesen.

- **Aufruf:** `wpcap_get_ticket( $ticket )` bzw. `PassCaptain\Api\Tickets::get( $ticket )` mit interner Ticket-ID
  (int) oder öffentlicher Ticket-ID (string, z. B. die `participation_id` des Review-Vertrags). Unbekannt → `null`.
- **Antwort:** `contract_version`, `ticket` (`id`, `public_id`, `status`, `refunded`, `checked_in` – gültiger Einlass
  nach Abzug von Rücknahmen –, `issued_at`, `ticket_type_id`, `ticket_type_name`), `order` (`id`, `public_id`,
  `status`, `payment_status`, `currency`), `event` (`id`, `name`, `status`), `occurrence` (`id`, `starts_at`,
  `ends_at` in ISO 8601 UTC, `timezone`, `status`).
- **Keine Personendaten:** keine E-Mail, kein Name, keine Teilnehmerangaben, kein Ticket-/Hilfecode, kein Token.
- **Erweiterung:** Filter `wp_pass_captain_ticket_read_v1( array $data, object $row )` darf nur Schlüssel ergänzen
  (nie Personendaten). Test: `tests/integration/modules/zz_a709.php`.

## Fixtures und Tests

Die Verträge liegen als JSON unter `tests/contracts/` (eine Datei je Schnittstelle: `public`, `partner`, `developer`, `app`, `offline`, `voucher-bridge`, `affiliate`, `actions`, `review`). Jeder Eintrag beschreibt einen Endpunkt bzw. Aufruf:

- `direction: inbound` (andere rufen Pass Captain auf): `request` ist ein Beispiel mit Platzhaltern `{{name}}`, `response` ist die Shape der Antwort.
- `direction: outbound` (Pass Captain ruft Voucher Captain oder Affiliate Captain auf): `request` ist die Shape dessen, was Pass Captain sendet, `response` ist ein Beispiel der kleinsten gültigen Antwort der Gegenseite.

Eine Shape ist JSON. Ein String ist ein Typ (`int`, `float`, `number`, `string`, `bool`, `null`, `array`, `object`, `any`, Vereinigungen mit `|`, z. B. `string|null`). `[shape]` ist eine Liste, `{…}` ein Objekt. Ein Feld mit `?` vor dem Namen ist optional, `*` gilt für alle weiteren Schlüssel (Maps). Zusätzliche Felder sind immer erlaubt.

`tests/integration/modules/zz_a203.php` ruft die echten Handler mit den Beispiel-Requests auf und vergleicht die Antworten (bzw. die gesendeten Payloads) mit den Shapes. Der Test schlägt fehl, wenn ein Feld fehlt, umbenannt wurde oder seinen Typ ändert, oder wenn ein Request-Parameter aus dem Beispiel nicht mehr verstanden wird. Außerdem prüft er unbekannte Zusatzfelder, fehlende optionale Felder, die Versionsprüfung sowie Ausnahmen, Timeouts und fehlerhafte Antworten der Gegenseite.

Neue Felder fügt man der Fixture hinzu (optional mit `?`, wenn sie nicht immer vorkommen). Für einen neuen Endpunkt legt man den Eintrag mit `request` an und startet die Tests einmal mit `WPCAP_CONTRACTS_RECORD=1`. Die fehlende Shape wird dann aus der echten Antwort geschrieben und muss von Hand geprüft werden (nullable Felder, optionale Felder, leere Listen). Ein Feld aus der Fixture zu entfernen oder seinen Typ zu ändern ist ein Bruch des Vertrags und nur mit einer neuen Hauptversion zulässig.
