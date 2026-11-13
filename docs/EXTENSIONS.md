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

