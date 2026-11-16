# Erweiterungen, Kern frei und Premium-Add-on

Pass Captain ist als Kern-Plugin vollständig frei (GPL-2.0-or-later): ohne Lizenzprüfung, ohne gesperrte oder zeitlich begrenzte Funktionen und ohne eigenen Update-Server – Updates kommen von WordPress.org. Alles, was der Kern heute ausliefert, bleibt dauerhaft frei (Inventar und Regeln: [FREE-PREMIUM.md](FREE-PREMIUM.md), Entscheidung vom 13.11.2026). Premiumfunktionen gibt es nur im **separaten Add-on** `pass-captain-pro` mit eigenem Shop, eigenem Updater und eigener Lizenz; **nur das Add-on spricht mit WP License Captain**. Der Kern enthält keinen Lizenzclient, keine Route `wplc/v1`, keinen Header `Update URI` und keine eigenen Update-Filter – `tests/license-guard.php` erzwingt das in der CI.

Das Add-on und andere Erweiterungen docken nur über öffentliche, versionierte Schnittstellen an: die öffentliche Add-on-API (Einstieg `wpcap_loaded`, Admin-Seiten, Einstellungs-Tabs, Checkout, Berichte, Exporte, PDF, Mail und REST; [HOOKS.md](HOOKS.md#öffentliche-add-on-api), Vertrag `addon` 1.0.0), Adapter-Verträge ([CONTRACTS.md](CONTRACTS.md)) und den Connector-Vertrag `PassCaptain\Integrations\ConnectorInterface`; Adapter werden über `wp_pass_captain_connectors_v1` registriert und nur ausgeführt, wenn ihr Schlüssel in der site-lokalen Option `wpcap_enabled_connectors` freigegeben wurde. Erweiterungen dürfen bestehende Tickets und Einlass nie sperren – auch nicht, wenn ein externer Dienst, der Lizenzserver oder die Add-on-Lizenz ausfällt; dann ist nur die jeweilige Add-on-Funktion aus.

Hintergrundadapter können idempotente Jobs über `PassCaptain\Jobs::enqueue()` einplanen. Ein Handler registriert einen Filter auf `wpcap_process_job` und gibt `true` zurück, wenn der Job erfolgreich und wiederholbar verarbeitet wurde; andernfalls (auch bei einer Exception) wird er mit Backoff erneut versucht und nach acht Läufen als `dead_letter` markiert. Jobs laufen mindestens einmal – Handler müssen idempotent sein (siehe [OPERATIONS.md](OPERATIONS.md#garantien-bei-worker-fehlern-und-neustarts-a188)).

## Affiliate Captain

`src/Integrations/AffiliateCaptain/Adapter.php` verbindet Pass Captain mit Affiliate Captain über dessen öffentlichen Vertrag v1 (`wpac_record_sale()`, `wpac_record_refund()`, `wpac_resolve_partner_code()`, `wpac_current_touch()`). Kein Zugriff auf Tabellen von Affiliate Captain; ohne Affiliate Captain oder bei anderer Vertrags-Hauptversion (bzw. wenn `wpac_contract_version()` scheitert) ist der Adapter inaktiv, jede Minor-Version von 1.x wird akzeptiert. Ausnahmen und Timeouts von Affiliate Captain brechen den Pass-Captain-Ablauf nie ab: Sie werden als `affiliate_sale_failed` auditiert, die Bestellung gilt dann als nicht gemeldet und wird beim nächsten `wpcap_order_paid` genau einmal gemeldet. Versionsregel und Vertrags-Fixtures: [CONTRACTS.md](CONTRACTS.md).

| Pass Captain | Affiliate Captain |
|---|---|
| `wpcap_order_creating` | Partnercode prüfen, Referral (Code, Link/Cookie, Kanal) im `price_snapshot` speichern |
| `wpcap_order_paid` | Verkauf `source = pass-captain`, eine Position je Bestellzeile, SKU `PASS-E{Event}-T{Ticketart}` (Regeln z. B. `PASS-E12-*`), Basis = bezahlter Anteil ohne USt, `hold_until` = Terminende |
| `wpcap_refund_completed` | anteilige Umkehr für die erstatteten Tickets (Nettobetrag der Erstattung) |
| `wpcap_order_refunded` / `wpcap_order_cancelled` | Umkehr des Rests (nur wenn ein Verkauf gemeldet wurde) |
| Bestellungen über WooCommerce | werden nicht gemeldet – Affiliate Captain erfasst die Woo-Bestellung selbst |

Ergebnisse stehen im Audit-Log (`affiliate_sale`, `affiliate_refund`, `…_queued`, `…_failed`). Filter siehe `docs/HOOKS.md`.

