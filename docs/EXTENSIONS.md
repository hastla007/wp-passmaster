# Erweiterungen und Lizenzierung

WP Pass Captain bleibt unter GPL-2.0-or-later nutzbar und seine Kernfunktionen hängen nicht von einem externen Lizenzserver ab. Der Connector-Vertrag `PassCaptain\Integrations\ConnectorInterface` ist versioniert; Adapter werden über `wp_pass_captain_connectors_v1` registriert und nur ausgeführt, wenn ihr Schlüssel in der site-lokalen Option `wpcap_enabled_connectors` freigegeben wurde.

Derzeit gibt es keinen Lizenzserver, Update-Service, kommerziellen Feature-Schalter oder Marketplace. Erweiterungen dürfen bestehende Tickets und Einlass nicht sperren, wenn ein externer Dienst ausfällt. Vor einem kommerziellen Modell müssen Datenschutz, Verlängerung, Offline-Verhalten, Kündigung, Schlüsselrotation und GPL-Kompatibilität festgelegt werden.

Hintergrundadapter können idempotente Jobs über `PassCaptain\Jobs::enqueue()` einplanen. Ein Handler registriert einen Filter auf `wp_pass_captain_process_job_v1` und gibt `true` zurück, wenn der Job erfolgreich und wiederholbar verarbeitet wurde; andernfalls wird er mit Backoff erneut versucht und nach acht Versuchen als `dead_letter` markiert.

## WP Affiliate Captain

Der Adapter `src/Integrations/AffiliateCaptain/Adapter.php` verbindet Pass Captain mit WP Affiliate Captain über dessen öffentlichen Vertrag v1 (`wpac_record_sale()`, `wpac_record_refund()`, `wpac_resolve_partner_code()`, `wpac_current_touch()`). Er greift nie auf Tabellen von Affiliate Captain zu und ist inaktiv, solange Affiliate Captain fehlt oder eine andere Vertrags-Hauptversion meldet.

| Pass Captain | Affiliate Captain |
|---|---|
| Bestellung bezahlt (`wpcap_order_paid`) | Verkauf `source = pass-captain`, eine Position je Bestellzeile, Nettobasis nach anteiligem Kampagnenrabatt und ohne USt (genau ein aktives Steuerprofil der Steuerklasse, sonst Preis = netto), SKU `PASS-E{Event}-T{Ticketart}`, Haltefrist bis Eventende |
| Storno einer bezahlten Bestellung | `cancellation` (volle Umkehr) |
| Vollerstattung | `refund`, `full = true` |
| Eventabsage | anteiliger `refund` der Positionen dieses Events, mit genau den gemeldeten Nettobeträgen |
| WooCommerce-Bestellung | wird nicht gemeldet (Affiliate Captain erfasst die Woo-Bestellung selbst) |

Filter: `wpcap_affiliate_hold_until`, `wpcap_affiliate_net_minor`, `wpcap_affiliate_sale_payload`, `wpcap_affiliate_sources`, `wpcap_affiliate_partner_field`. Ergebnisse und Fehler stehen im Audit-Log (`affiliate_sale`, `affiliate_refund`, `…_queued`, `…_failed`).
