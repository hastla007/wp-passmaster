# Umbenennung: „WP Pass Captain“ → „Pass Captain“

Bis Version 1.4 hieß das Plugin **WP Pass Captain** (Ordner, Hauptdatei und Textdomain `wp-pass-captain`). Für das
Plugin-Verzeichnis auf WordPress.org heißt es jetzt **Pass Captain** mit dem Slug **`pass-captain`**: Namen und Slugs
dürfen dort nicht mit „WP“ beginnen, und die Textdomain muss dem Slug entsprechen, damit Sprachpakete von
translate.wordpress.org geladen werden.

## Was sich ändert

| Bereich | bis 1.4 | ab jetzt |
|---|---|---|
| Plugin-Name | WP Pass Captain | Pass Captain |
| Ordner / Hauptdatei | `wp-pass-captain/wp-pass-captain.php` | `pass-captain/pass-captain.php` |
| Textdomain, Sprachdateien | `wp-pass-captain`, `languages/wp-pass-captain-*.po` | `pass-captain`, `languages/pass-captain-*.po` |
| REST-Namensraum | `/wp-json/wp-pass-captain/v1/…` | `/wp-json/pass-captain/v1/…` |
| Admin-Seiten | `admin.php?page=wp-pass-captain…` | `admin.php?page=pass-captain…` |
| Release-ZIP | `wp-pass-captain.zip` | `pass-captain.zip` |
| Theme-Vorlagen (Eventkarte) | `<theme>/wp-pass-captain/event-card.php` | `<theme>/pass-captain/event-card.php` (alter Ordner wird weiter gelesen, siehe unten) |
| Action-Scheduler-Gruppe der Folgeläufe | `wp-pass-captain` | `pass-captain` (ausstehende Aktionen werden übernommen, siehe unten) |

**Unverändert:** alle Daten (Tabellen `{prefix}wpcap_*`, Optionen `wpcap_*`), Rollen und Rechte, PHP-Namensraum
`PassCaptain`, Hooks und Shortcodes (`wpcap_*`, die älteren Namen `wp_pass_captain_*` bleiben als Aliase; die
Shortcodes `[wp_pass_captain_events]`, `[wp_pass_captain_calendar]`, `[wp_pass_captain_scanner]` meldet WordPress bei
aktivem `WP_DEBUG` als veraltet, A303). Die versionierten Verträge mit den Partner-Plugins
(`wp_pass_captain_*_v1`, siehe HOOKS.md) behalten ihren Namen.

**Blöcke (A303):** Die Blöcke heißen `pass-captain/events` und `pass-captain/calendar` (wie
`pass-captain/service-booking`). Die früheren Namen `wp-pass-captain/events` und `wp-pass-captain/calendar` stehen im
gespeicherten Seiteninhalt; sie bleiben als im Inserter ausgeblendete Aliase registriert und werden auf der Website
unverändert ausgegeben. Öffnet man einen solchen Beitrag im Block-Editor, stellt der Editor den Block mit allen
Einstellungen auf den neuen Namen um; gespeichert wird das mit dem nächsten Speichern. Die Block-CSS-Klasse wechselt
dabei von `wp-block-wp-pass-captain-events` auf `wp-block-pass-captain-events` (bzw. `…-calendar`) – Theme-CSS, das
die alte Klasse anspricht, bitte um die neue ergänzen; die eigenen Klassen `wpcap-events`/`wpcap-calendar` bleiben.
Die Partner-Plugins heißen entsprechend **Voucher Captain** und **Affiliate Captain**.

## Umstieg auf einer bestehenden Website

1. Sicherung von Dateien und Datenbank (wie vor jedem Update, siehe OPERATIONS.md).
2. `pass-captain.zip` unter *Plugins → Installieren → Plugin hochladen* installieren und aktivieren – die alte Kopie
   bleibt dabei installiert.
3. Pass Captain schaltet die alte Kopie automatisch ab (beide dürfen nie gleichzeitig laufen) und übernimmt alle
   Daten unverändert; das Datenbank-Upgrade läuft beim nächsten Aufruf des Admin-Bereichs.
4. Die alte Kopie unter *Plugins* (bzw. im Netzwerk-Admin) löschen. Solange ihr Ordner existiert, ist „Beim Löschen des Plugins alle Daten entfernen“
   pausiert (ein Hinweis zeigt das an): Das Löschen der alten Kopie führt deren `uninstall.php` aus, die sonst die
   gemeinsamen Tabellen entfernen würde. Danach wird die Einstellung automatisch wiederhergestellt und die von der
   alten Kopie entfernten Cron-Ereignisse werden neu eingeplant.

   Die Pause greift bei jedem Laden von Pass Captain (Admin, Frontend, Cron, WP-CLI) und auf einem Multisite-Netzwerk
   für **alle** Sites – die `uninstall.php` der alten Kopie läuft über alle Sites des Netzwerks, auch über solche,
   deren Admin-Bereich seit dem Umstieg niemand geöffnet hat. Fehlende Cron-Ereignisse plant jede Site beim nächsten
   Aufruf selbst neu ein.

## Alte Adressen funktionieren weiter

Externe Systeme müssen nicht sofort umgestellt werden:

- **REST:** Anfragen an `/wp-json/wp-pass-captain/v1/…` werden an `/wp-json/pass-captain/v1/…` weitergeleitet
  (gleiche Rechte- und Signaturprüfung) und mit `Deprecation: true` und `Link: <neue URL>; rel="successor-version"`
  beantwortet. Das betrifft insbesondere
  - Zahlungs-Webhooks, die bei Stripe, PayPal und Mollie hinterlegt sind,
  - Twilio-Status- und Eingangs-Callbacks (die Signatur wird gegen die tatsächlich aufgerufene alte URL geprüft),
  - Apple-Wallet-Pässe, die bereits auf Geräten liegen (die Web-Service-URL steht im Pass),
  - gekoppelte Scanner-Apps, Offline-Scanner und Partner-/Entwickler-API-Integrationen.
- **Admin:** Lesezeichen auf `admin.php?page=wp-pass-captain…` leiten auf die neuen Seiten um.

- **Theme-Vorlagen (A659):** Eine überschriebene Eventkarte im alten Theme-Ordner
  `<theme>/wp-pass-captain/event-card.php` wird weiter verwendet, solange es keine im neuen Ordner
  `<theme>/pass-captain/` gibt (Child-Theme vor Eltern-Theme, wie bei `locate_template()`); der neue Ordner hat
  immer Vorrang. Wird die alte Datei benutzt, zeigt der Admin-Bereich Nutzer:innen mit dem Recht
  `switch_themes` einen Hinweis mit dem Pfad der Datei (relativ zum Themes-Ordner, je Person ausblendbar), und
  WordPress meldet bei `WP_DEBUG` einmal pro Aufruf eine Veraltet-Meldung (`_deprecated_file()`). Sobald die
  Datei verschoben oder gelöscht ist, verschwindet der Hinweis von selbst. Die Unterstützung des alten Ordners
  entfällt frühestens in einer späteren Hauptversion – Themes sollten die Datei jetzt verschieben.
- **Action Scheduler (A660):** Folgeläufe der Warteschlangen (`wpcap_process_messages`, `wpcap_process_jobs`,
  `wpcap_webhooks_dispatch`, `wpcap_waitlist_process` mit Argument `continue`) lagen bis 1.4 in der Gruppe
  `wp-pass-captain`, jetzt in `pass-captain`. Beim ersten `action_scheduler_init` nach dem Update werden
  ausstehende Aktionen der alten Gruppe ausgeplant und – falls in der neuen Gruppe noch keine wartet – dort
  einmal neu eingeplant (Option `wpcap_legacy_as_group_migrated`, Audit `legacy_action_scheduler_group_migrated`).
  Bis dahin zählt eine wartende Aktion der alten Gruppe als „bereits eingeplant“, es entsteht kein zweiter Lauf.
  Das Deaktivieren entfernt Aktionen beider Gruppen.

Empfehlung: Webhook-URLs bei den Zahlungs- und SMS-Anbietern auf `/wp-json/pass-captain/v1/…` umstellen (die
aktuellen Adressen stehen in den Einstellungen) und Partnern den neuen Namensraum mitteilen. Der alte Namensraum
bleibt mindestens für die Lebensdauer der ausgegebenen Wallet-Pässe erhalten.

## Abgesichert durch Tests

- `tests/integration/modules/zz_a659.php`: Eventkarte aus dem alten Theme-Ordner, Vorrang des neuen Ordners,
  Hinweis (Rechte, Ausblenden mit Nonce, verschwindet nach dem Verschieben), Veraltet-Meldung.
- `tests/integration/modules/zz_a660.php`: ausstehende Action-Scheduler-Aktionen der alten Gruppe werden erkannt
  (kein Doppeleintrag), einmal übernommen und beim Deaktivieren entfernt.
- `tests/integration/modules/zz_legacy_slug.php`: Weiterleitung, Header, Signatur-URL, Admin-Slugs, Pausieren und
  Wiederherstellen der Datenlöschung, kein alter Slug außerhalb der Kompatibilitätsschicht.
- `tests/wp-matrix/rename-takeover.sh` (CI, jede WordPress-Version der Matrix): echte Website mit dem
  Vorgänger-Release und Daten → neues ZIP installieren und aktivieren → alte Kopie abgeschaltet, Daten unverändert,
  alte REST-URLs antworten, Löschen der alten Kopie behält die Daten, Cron-Ereignisse wieder eingeplant, keine
  PHP-Meldungen.
- `tests/wp-matrix/rename-takeover-ms.sh` (CI): dasselbe auf einem Multisite-Netzwerk – alte Kopie netzwerkweit aktiv, Subsite mit „Daten beim Löschen entfernen“, Deinstallation der alten Kopie im Netzwerk ohne Aufruf der Subsite: Tabellen und Daten bleiben, Einstellung wird wiederhergestellt, Cron neu eingeplant.
- `tests/wp-matrix/zip-install.sh`: das neue ZIP in einer frischen Installation.
