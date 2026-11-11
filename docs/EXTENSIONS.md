# Erweiterungen und Lizenzierung

WP Pass Captain bleibt unter GPL-2.0-or-later nutzbar und seine Kernfunktionen hängen nicht von einem externen Lizenzserver ab. Der Connector-Vertrag `PassCaptain\Integrations\ConnectorInterface` ist versioniert; Adapter werden über `wp_pass_captain_connectors_v1` registriert und nur ausgeführt, wenn ihr Schlüssel in der site-lokalen Option `wpcap_enabled_connectors` freigegeben wurde.

Derzeit gibt es keinen Lizenzserver, Update-Service, kommerziellen Feature-Schalter oder Marketplace. Erweiterungen dürfen bestehende Tickets und Einlass nicht sperren, wenn ein externer Dienst ausfällt. Vor einem kommerziellen Modell müssen Datenschutz, Verlängerung, Offline-Verhalten, Kündigung, Schlüsselrotation und GPL-Kompatibilität festgelegt werden.

Hintergrundadapter können idempotente Jobs über `PassCaptain\Jobs::enqueue()` einplanen. Ein Handler registriert einen Filter auf `wp_pass_captain_process_job_v1` und gibt `true` zurück, wenn der Job erfolgreich und wiederholbar verarbeitet wurde; andernfalls wird er mit Backoff erneut versucht und nach acht Versuchen als `dead_letter` markiert.
