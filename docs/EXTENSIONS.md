# Erweiterungen und Lizenzierung

Pass Captain bleibt unter GPL-2.0-or-later nutzbar und seine Kernfunktionen hängen nicht von einem externen Lizenzserver ab. Der Connector-Vertrag `PassCaptain\Integrations\ConnectorInterface` ist versioniert; Adapter werden über `wp_pass_captain_connectors_v1` registriert und nur ausgeführt, wenn ihr Schlüssel in der site-lokalen Option `wpcap_enabled_connectors` freigegeben wurde.

Derzeit gibt es keinen Lizenzserver, Update-Service, kommerziellen Feature-Schalter oder Marketplace. Erweiterungen dürfen bestehende Tickets und Einlass nicht sperren, wenn ein externer Dienst ausfällt. Vor einem kommerziellen Modell müssen Datenschutz, Verlängerung, Offline-Verhalten, Kündigung, Schlüsselrotation und GPL-Kompatibilität festgelegt werden.

Hintergrundadapter können idempotente Jobs über `PassCaptain\Jobs::enqueue()` einplanen. Ein Handler registriert einen Filter auf `wp_pass_captain_process_job_v1` und gibt `true` zurück, wenn der Job erfolgreich und wiederholbar verarbeitet wurde; andernfalls wird er mit Backoff erneut versucht und nach acht Versuchen als `dead_letter` markiert.

## WP Affiliate Captain

`src/Integrations/AffiliateCaptain/Adapter.php` verbindet Pass Captain mit WP Affiliate Captain über dessen öffentlichen Vertrag v1 (`wpac_record_sale()`, `wpac_record_refund()`, `wpac_resolve_partner_code()`, `wpac_current_touch()`). Kein Zugriff auf Tabellen von Affiliate Captain; ohne Affiliate Captain oder bei anderer Vertrags-Hauptversion ist der Adapter inaktiv.

| Pass Captain | Affiliate Captain |
|---|---|
| `wpcap_order_creating` | Partnercode prüfen, Referral (Code, Link/Cookie, Kanal) im `price_snapshot` speichern |
| `wpcap_order_paid` | Verkauf `source = pass-captain`, eine Position je Bestellzeile, SKU `PASS-E{Event}-T{Ticketart}` (Regeln z. B. `PASS-E12-*`), Basis = bezahlter Anteil ohne USt, `hold_until` = Terminende |
| `wpcap_refund_completed` | anteilige Umkehr für die erstatteten Tickets (Nettobetrag der Erstattung) |
| `wpcap_order_refunded` / `wpcap_order_cancelled` | Umkehr des Rests (nur wenn ein Verkauf gemeldet wurde) |
| Bestellungen über WooCommerce | werden nicht gemeldet – Affiliate Captain erfasst die Woo-Bestellung selbst |

Ergebnisse stehen im Audit-Log (`affiliate_sale`, `affiliate_refund`, `…_queued`, `…_failed`). Filter siehe `docs/HOOKS.md`.


## Form Captain

`src/Integrations/FormCaptain/` (registriert über das Modul `src/Modules/FormCaptain.php`) bettet ein Form-Captain-Formular für Anfragen, Bewerbungen oder freiwillige Zusatzangaben in die Eventseite ein. Grundlage ist der öffentliche Form-Captain-Vertrag v1 ab 1.1.0 (`form_captain_get_form_v1()`, `form_captain_get_render_v1()`, `form_captain_register_context_purpose_v1()`, `form_captain_sign_context_v1()`, Filter `form_captain_accepted_context_purposes_v1`). Kein Zugriff auf Form-Captain-Tabellen; ohne Form Captain ist der Adapter inaktiv, bei anderer Vertrags-Hauptversion registriert er nichts.

| Schritt | Umsetzung |
|---|---|
| Kontextzweck | `pass.event_registration` nach `form_captain_loaded_v1` (spätestens auf `init`): Eigentümer `pass`, `ref_types` `['event']`, Ausgabeweg `public_endpoint`, TTL 12 h / max. 24 h, nicht einweg, `marks_verified`. `issue_check` und `submit_check`: Event veröffentlicht, mindestens ein Termin nicht abgesagt, Formular ist das dem Event zugeordnete. |
| Zuordnung | Event-Adminseite, Abschnitt „Formular (Form Captain)“ (Action `wpcap_event_admin_sections`, Argumente `$event`, `$editable`): Formular-ID oder öffentliche ID, geprüft mit `form_captain_get_form_v1()`, nur veröffentlichte Formulare. Gespeichert in der Pass-Captain-Tabelle `wpcap_event_forms`; Recht `wpcap_manage_events` mit Event-Scope; Audit `event_form_assigned`/`event_form_removed`. Für zugeordnete Formulare nimmt der Adapter den Zweck über `form_captain_accepted_context_purposes_v1` an, die Formular-Einstellungen bleiben unverändert. |
| Ausgabe | Filter `wp_pass_captain_event_card_html`: Formular in der Event-Karte nach dem Checkout-Formular, je Event einmal pro Seite. `form_captain_get_render_v1()` bekommt `context_ref` (`event:<id>`, frischer Kontext über die no-store-Token-Route von Form Captain) und als Rückfall einen beim Rendern signierten Kontext mit 24 h Laufzeit. Dieser ist nicht personenbezogen und mehrfach verwendbar; gecachte Seiten bleiben damit nutzbar, nach Ablauf bittet Form Captain um erneutes Absenden. Ohne Zuordnung, bei Entwurf oder abgesagtem Event wird nichts ausgegeben. |
| Abgrenzung | Eine Einsendung ist keine Buchung: Der Adapter registriert keine Formularaktion und hört nicht auf Entry-Ereignisse; es entstehen keine Reservierung, Zahlung oder Ticket. Ticket-Teilnehmerfelder und Buchungsbestätigung bleiben in Pass Captain. Eine explizite Übergabe von Formulardaten (Formularaktion mit Feld-Allowlist) ist erst nach Freigabe vorgesehen (TODO PASS-FC-05). |

Tests: `tests/integration/modules/form_captain.php` (mit nachgebildeten Vertragsfunktionen) und der Zwei-Plugin-Test gegen das echte Form Captain in `tests/form-captain/` (siehe `docs/TESTING.md`).
