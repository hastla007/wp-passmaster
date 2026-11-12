# Betrieb und Wiederherstellung

## Vor einem Upgrade

1. Upgrade zuerst in einer Staging-Site mit derselben PHP-, WordPress-, Datenbank- und Integrationsversion einspielen.
2. Dateien und vollständige WordPress-Datenbank unmittelbar vor dem Upgrade sichern. Die Präfix-Tabellen `*_wpcap_*` enthalten Aufträge, Reservierungen, Tickets, Einlassprotokolle und weitere Plugin-Daten.
3. Sicherungszeitpunkt, Plugin-Version und Schema-Version notieren. Die Migration v3 nutzt `dbDelta` und ergänzt Tabellen und Spalten; sie löscht keine Plugin-Tabellen.
4. Nach dem Upgrade Datenbankverbindung, öffentliche Events, Checkout, Ticketmail, Check-in und geplante Jobs in der Staging-Site prüfen.

## Schema-Upgrade nach einem Update

Das Datenbank-Upgrade (`dbDelta` für alle Kern- und Modultabellen) läuft nicht mehr bei jedem Seitenaufruf:

- **Admin-Aufrufe** (`admin_init`), **WP-Cron** und **WP-CLI** vergleichen die gespeicherte Schema-Version (`wpcap_db_version`, Kernversion plus Hash der Modul-Schemata) und führen das Upgrade aus. Es läuft hinter einer Datenbanksperre (`GET_LOCK`, je Site); parallele Aufrufe ohne Sperre überspringen es und arbeiten mit dem bisherigen Schema weiter. Die Sperre endet automatisch, wenn der Prozess abbricht.
- **Frontend- und REST-Aufrufe** prüfen nur einen günstigen Fingerabdruck (Schema-/Plugin-Version und Modulliste, Option `wpcap_schema_fingerprint`). Weicht er ab, wird einmalig das Cron-Ereignis `wpcap_run_upgrade` eingeplant. Nur wenn dieses Ereignis länger als fünf Minuten überfällig ist (WP-Cron läuft nicht), übernimmt ein einzelner Frontend-Aufruf das Upgrade hinter derselben Sperre.
- Bis zum Upgrade läuft der neue Code gegen das alte Schema. Das ist unkritisch, weil Migrationen nur Tabellen, Spalten und Indizes ergänzen; nach einem Update trotzdem zeitnah den Admin-Bereich öffnen oder `wp cron event run wpcap_run_upgrade` ausführen.

## Hintergrundverarbeitung (WP-Cron)

E-Mail-Warteschlange (`wpcap_process_messages`, zusammen mit SMS), Jobs und Webhooks (`wpcap_process_jobs`, `wpcap_webhooks_dispatch`) laufen alle fünf Minuten. Jeder Lauf arbeitet mit einem gemeinsamen **Zeitbudget von 20 Sekunden** (höchstens `max_execution_time` − 15 s; Filter `wpcap_cron_time_budget`) und bis zu 500 E-Mails (Filter `wpcap_message_batch_size`). Das Budget wird vor jeder Entnahme geprüft, sodass kein Eintrag reserviert und dann liegen gelassen wird; bricht PHP während eines Eintrags ab, wird die Reservierung beim Beenden freigegeben. Bleibt ein Rückstand, plant der Lauf sofort einen Folgelauf ein – über Action Scheduler (`as_enqueue_async_action`, Gruppe `wp-pass-captain`), wenn vorhanden (z. B. mit WooCommerce), sonst als einzelnes WP-Cron-Ereignis.

WP-Cron startet nur bei Seitenaufrufen. Für verlässliche Zustellung (Ticketmails, Erinnerungen, Webhooks) WP-Cron durch einen System-Cronjob ersetzen:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```sh
# crontab des Webserver-Benutzers: jede Minute fällige Ereignisse ausführen
* * * * * cd /pfad/zu/wordpress && wp cron event run --due-now --quiet
# ohne WP-CLI:
* * * * * curl -fsS -o /dev/null "https://example.org/wp-cron.php?doing_wp_cron"
```

Auf Multisite-Netzwerken muss der Cronjob jede Site auslösen, z. B. `wp site list --field=url | xargs -I{} wp cron event run --due-now --url={}`.

## Multisite: Site löschen und Plugin deinstallieren

- Wird eine Site im Netzwerk gelöscht, entfernt WordPress auch deren `*_wpcap_*`-Tabellen (Filter `wpmu_drop_tables`). Tabellen anderer Sites sind nicht betroffen.
- Beim Deinstallieren entscheidet jede Site über „Daten beim Löschen entfernen“. Netzwerkweite Benutzer-Metadaten (`wpcap_*` ohne Site-Präfix) werden nur gelöscht, wenn alle Sites zugestimmt haben; Site-bezogene Benutzeroptionen (`{präfix}wpcap_*`) und Rollen/Berechtigungen nur auf zustimmenden Sites.

## Wiederherstellung

Dateien und Datenbank als zusammengehörigen Stand wiederherstellen. Ein Datenbank-Restore allein kann mit neuerem Plugin-Code inkompatibel sein. Für eine Netzwerksite gehören auch Netzwerkaktivierung und Site-Präfixe in den Wiederherstellungsplan. Nach einem Restore Cron-Ereignisse kontrollieren; WordPress-Cron benötigt Seitenaufrufe oder einen externen Cron-Trigger.

Auftrags- und Ticketdaten nicht selektiv aus mehreren Sicherungszeitpunkten zusammenführen. Zahlungsanbieter und optionale Integrationen müssen separat auf denselben externen Transaktionsstand abgeglichen werden.

## Release-Kandidat

Vor einer stabilen Version müssen Upgrade und Restore mit realistischen Datenbankständen, Eventänderungen, doppelten Webhooks, E-Mail-Ausfällen, hoher Scanrate und Multisite geprüft werden. Der ZIP-Build (`bash build/release.sh`) exportiert den committeten Stand per `git archive` (Standard `HEAD`, anders mit `WPCAP_BUILD_REF=<ref>`), installiert dort die Composer-Abhängigkeiten ohne Dev-Pakete und lässt alles weg, was in `.distignore` steht (Tests, Doku, Markdown außer `readme.txt`, `.po`-Quellen, Test-/CI-Dateien der Abhängigkeiten). Nicht committete Änderungen landen also nie im Paket. Der Release-Workflow veröffentlicht nur nach erfolgreicher CI auf `main` (genau der getestete Commit), bei Tags `v*` oder manuell von `main`.

## Kasse: keine Registrierkasse

Die Kasse (Menü **Pass Captain → Kasse**) bucht Verkäufe vor Ort als Bestellungen, erzeugt Belege mit fortlaufender Nummer und einen Kassenabschluss. Sie ist **keine** Registrierkasse im fiskalrechtlichen Sinn:

- **Österreich (RKSV):** Bareinnahmen (dazu zählen auch Karten- und Wallet-Zahlungen vor Ort) erfordern ab den gesetzlichen Umsatzgrenzen eine Registrierkasse mit Sicherheitseinrichtung (Signaturerstellungseinheit), registriert bei FinanzOnline, inkl. Start-, Monats- und Jahresbeleg.
- **Deutschland (KassenSichV, § 146a AO):** Elektronische Aufzeichnungssysteme mit Kassenfunktion benötigen eine zertifizierte technische Sicherheitseinrichtung (TSE, BSI TR-03153) und sind dem Finanzamt zu melden; Belege müssen die TSE-Daten enthalten.

Die Kasse des Plugins signiert keine Belege und hat keine TSE-/Signaturkarten-Anbindung. Für Bar- und Kartenzahlungen vor Ort deshalb eine zertifizierte Kasse verwenden und das Plugin nur für Ticketausgabe und Einlass nutzen, oder die Rechtslage mit der Steuerberatung klären. Der Hinweis auf der Kassenseite kann pro Benutzer ausgeblendet werden; der Link lässt sich über den Filter `wpcap_boxoffice_fiscal_docs_url` ändern.

## Datenschutz: Löschfristen, Auskunft und Löschung

**Täglicher Löschlauf** (`wpcap_privacy_retention`, WordPress-Cron, Stapel zu 500 Zeilen, Zeitbudget 20 s; der nächste Lauf setzt fort). Bezugspunkt ist das Terminende (`ends_at`, sonst Beginn + 1 Tag):

| Daten | Frist | Wirkung |
|---|---|---|
| Teilnehmerangaben (Zusatzfelder) | Einstellung „Teilnehmerdaten nach dem Event löschen nach“; bei 0 die Frist des Felds | gelöscht |
| Teilnehmername/-E-Mail (Zuordnungen, Tickets), Namen in Offline-Paketen, Apple-Wallet-Geräteregistrierungen | Einstellung; bei 0 365 Tage (`wpcap_attendee_default_retention_days`) | geleert/gelöscht; Ticket, Bestellung und Einlassprotokoll bleiben |
| Teilnehmerangaben unbezahlter (abgelaufener/stornierter) Bestellungen | 1 Tag | `attendee_data` geleert |
| Käufer-E-Mail nie bezahlter Bestellungen (ohne Ticket, ohne Rechnung) | 90 Tage | durch Platzhalter `deleted+…@invalid.example` ersetzt |
| Versandte/fehlgeschlagene Nachrichten der Warteschlange | 30 Tage / 365 Tage | Empfänger und Inhalt geleert / Zeile gelöscht |
| Einlassprotokoll: Mitarbeitende und Gerät | 365 Tage | `user_id`/`device_id` = 0 (Zeit, Ergebnis, Ticket bleiben) |
| Audit-Log | 730 Tage | gelöscht |

Alle Fristen außer den Teilnehmerfristen lassen sich mit `wpcap_privacy_retention_days` ändern oder abschalten (siehe [HOOKS.md](HOOKS.md)). Das Audit-Log erhält je Lauf nur die Anzahlen (`privacy_retention`). Nicht erfasst: Bestellungen, Positionen, Rechnungen, Gutschriften und Erstattungen (gesetzliche Aufbewahrung), Bestellungen in WooCommerce (eigene Fristen des Shops), Daten bei Zahlungsanbietern, Partnern und Webhook-Empfängern. Webhook-Zustellungen haben eine eigene Frist (Modul Webhooks).

**Auskunft und Löschung** (Werkzeuge → Personenbezogene Daten exportieren/löschen): Der Export enthält Bestellungen (mit Rechnung und Käuferangaben der Rechnung), alle Tickets der Person als Käufer oder Teilnehmer mit Name, Zusatzangaben, Einlassprotokoll (Zeit, Bereich) und Wallet-Pässen, Pässe, Warteliste und Terminbuchungen. Teilnehmende erhalten nur ihre eigenen Tickets, nicht die Bestellung des Käufers. Die Löschung widerruft gültige Tickets über den Ticketdienst (Wallet, Sitzplätze, Offline-Pakete und Webhooks werden informiert), entfernt Teilnehmerdaten, Antworten, Offline-Namen, Nachrichten und Terminbuchungen und löscht Apple-Wallet-Registrierungen eine Stunde später (nach der Sperr-Push-Nachricht). Rechnungen bleiben samt Käuferangaben bis zum Ende der Aufbewahrungsfrist erhalten (Art. 17 Abs. 3 lit. b DSGVO). Hat die Person Tickets für kommende Termine, weist die Löschung darauf hin; Erstattung oder Storno müssen Sie selbst veranlassen.

**Pflichten des Betreibers:**

- WordPress-Cron zuverlässig auslösen (siehe oben), sonst greifen die Fristen nicht.
- Den Vorschlag unter **Einstellungen → Datenschutz → Leitfaden** prüfen, an Verantwortlichen, Verträge und Abläufe anpassen und in die Datenschutzerklärung übernehmen; nach Aktivieren weiterer Dienste (Zahlungsanbieter, SMS, Wallet, Partner, Webhooks, Affiliate) erneut prüfen.
- Auftragsverarbeitungsverträge mit Hosting, E-Mail-Versand, SMS-Anbieter und gegebenenfalls Partnern bzw. Webhook-Empfängern schließen; Übermittlungen in die USA (Stripe, PayPal, Twilio, Apple, Google) dokumentieren.
- Affiliate-Tracking: Mit dem Plugin „WP Consent API“ und einem kompatiblen Cookie-Banner wird der Empfehlungs-Cookie nur mit Einwilligung „marketing“ ausgewertet. Ohne Consent-API muss der Cookie-Banner das Setzen des Affiliate-Cookies selbst blockieren, oder der Filter `wpcap_affiliate_tracking_allowed` wird an den eigenen Consent-Manager angebunden.
- Web-Scanner auf geteilten Geräten: vor Schichtwechsel synchronisieren, nach Schichtende Browser schließen bzw. Website-Daten löschen (Offline-Scans liegen bis zu 24 Stunden im Browser).
- Gesetzliche Aufbewahrung von Rechnungen und Buchungsdaten (7 Jahre § 132 BAO, 10 Jahre § 147 AO/§ 257 HGB) überwachen und danach manuell löschen; das Plugin löscht Finanzdaten nicht automatisch.
- Partner-API-Schlüssel mit `attendees:read` nur mit Rechtsgrundlage vergeben; gelöschte Adressen liefert die API als `null`.
