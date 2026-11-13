# Pass Captain – Schritte 31–50

Diese Stufen folgen auf die Kern- und Integrationsausbaustufen 1–30. Sie erweitern Anpassbarkeit, Veranstalterbetrieb und Plattformreife; sie setzen die abgeschlossenen Sicherheits- und Datenmodelle der vorherigen Stufen voraus.

## 31. Event- und Ticketvorlagen — Eventkarten-Vorlage umgesetzt

Eventkarten lassen sich über ein Theme-Template und einen Filter überschreiben. Ticket- und E-Mail-Templates folgen noch.

## 32. Teilnehmerfelder und Formulare — Datenmodell angelegt

Tabellen für Ticketfelder und Antworten sind angelegt. Form-Editor, Checkout-Erfassung, Antwortvalidierung, Export/Löschung und Einwilligungssteuerung fehlen; sensible Felder sind nicht freigeschaltet.

## 33. Geplante Kommunikation — Queue-Grundlage umgesetzt

Idempotente Nachrichtenqueue mit geplantem Versand, Retry-Backoff und Fehlerstatus ist vorhanden. Versandvorlagen und konkrete Reminder-/Eventänderungsjobs müssen noch ergänzt werden.

## 34. Teilnehmergruppen und Sammelbestellungen — Einzelzuweisung umgesetzt

Mehrere Tickets pro Bestellung bleiben möglich; Käufer können einzelne Tickets Teilnehmern zuweisen, Teilnehmer erhalten rotierte Codes. Gruppenverwaltung und Teamrollen fehlen.

## 35. Mitgliedschaften und Saisonpässe — Ausgabe- und Einlöse-Grundlage umgesetzt

Passcodes mit Gültigkeitszeitraum, Event-/Ticketregeln, atomaren Nutzungskontingenten, Idempotency und Widerruf sind implementiert. Abozahlungen, Admin-UI und vollständige End-to-End-Abnahme fehlen.

## 36. Kampagnen und Preisregeln — teilweise umgesetzt

REST-Kampagnencodes unterstützen Prozent-/Festbetragsrabatte, Event-/Ticket-Scope, Priorität und ein unter Transaktion gesperrtes globales Limit; der Auftrag speichert Preis-Snapshot. Early-Bird-Autoregeln, per-Käufer-Grenzen, Stapelkonfiguration, Voucher-Bridges und Checkout-UI fehlen.

## 37. Wartelisten und Platzangebote — Queue und Angebot umgesetzt

Event-/Ticketwarteliste, geordnete Einträge, befristetes Angebot und Tokenannahme sind verfügbar. Das Angebot reserviert keine Kapazität; automatische Auslösung bei Freigabe, Ablehnung/Abmeldung und garantierte Ticketzuteilung fehlen.

## 38. Veranstalterberichte — Basisbericht umgesetzt

Eventbezogener Bericht liefert bezahlte Bestellungen, verkaufte Tickets, Bruttosummen pro Währung und Einlasszahlen mit Rollenprüfung und kurzer Cachezeit. CSV-Export, Bestand, Refunds, Kampagnen, Teilnehmerdaten und granularere Zeitraumvergleiche fehlen.

## 39. Öffentliche Entwickler-API — Read-only-Grundlage umgesetzt

Einmalig angezeigte, gehashte API-Schlüssel sind auf ein Event und `events.read`/`reports.read` begrenzt; Ablauf und Widerruf sind vorgesehen. Der erste Endpunkt liefert nur Eventdaten. Rate-Limits, Schlüsselverwaltung-UI, breite API-Abdeckung und dokumentierter Partner-Support fehlen.

## 40. WordPress-Multisite — getrennte Tabellen-Grundlage umgesetzt

Netzwerkaktivierung und neu erstellte Sites erhalten site-präfixierte Tabellen und Cron-Ereignisse. Zentrale Netzwerkverwaltung, Cross-Site-Abnahmetests und Netzwerkweite Berichte fehlen.

## 41. CRM-, Kalender- und Buchhaltungsintegrationen — Adaptervertrag umgesetzt

Versionierter Connector-Vertrag und site-lokale Opt-in-Registry sind vorhanden. Es gibt keine CRM-, Kalender- oder Buchhaltungsadapter, Einstellungsoberfläche, Mapping-UI oder Live-Synchronisierung.

## 42. Übersetzte Eventinhalte — Eventkarten-Grundlage umgesetzt

Lokalisierte Eventnamen, Beschreibungen und Orte können gespeichert und auf Eventlisten/-karten anhand des WordPress-Locales ausgegeben werden. E-Mails, Ticketvorlagen, Käufer-Sprachauswahl und Übersetzungs-UI fehlen.

## 43. Länderpakete und Steuerregeln — Entwurfsdatenmodell umgesetzt

Länder-/Steuerprofile lassen sich als Entwürfe versioniert speichern. Keine Profile werden aktiviert oder für Bestellungen/Rechnungen berechnet; fachliche Prüfung, Länderpakete und historische Steuer-Snapshots fehlen.

## 44. Hochlast- und Kapazitätsoptimierung — Indizes und Berichtscache ergänzt

Zusätzliche Reporting-Indizes und ein kurzer Cache für Eventberichte sind ergänzt. Abfragepläne, Cache-Invalidierung, Benchmarking, Lasttests und Spitzenlast-Abnahme fehlen.

## 45. Hintergrundverarbeitung und Wiederholung — generische Queue-Grundlage umgesetzt

Generische Jobtabelle mit Idempotenz, Claiming, exponentiellem Retry und `dead_letter`-Status ergänzt. Jobhandler und Admin-Ansicht für Wiederholung fehlen; WordPress-Cron ist abhängig von Traffic/externem Cron.

## 46. Backup und Notfallwiederherstellung — Runbook dokumentiert

Backup-/Restore-Runbook in `docs/OPERATIONS.md`; Wiederherstellungs- und Wiederanlaufübungen mit realen Daten fehlen.

## 47. Unabhängige Sicherheitsprüfung — Bedrohungsgrenzen dokumentiert

Schutzgrenzen und Meldungshinweise in `docs/SECURITY.md`; externe Prüfung, Penetrationstest und formeller Incident-Prozess fehlen.

## 48. Barrierefreiheitsprüfung — Basisverbesserungen, Audit offen

Sichtbare Fokusse, Mindestzielhöhen und Reduced-Motion-Regel ergänzt; Statusmeldungen hatten bereits `aria-live`. Manuelle Tastatur-/Screenreader-Prüfung und Kontrast-/Zoom-Audit stehen aus.

## 49. Erweiterungs- und Lizenzmodell — Richtlinie dokumentiert

`docs/EXTENSIONS.md` hält GPL-Kern, opt-in Connector-Vertrag und Lizenzserver-unabhängiges Ticketverhalten fest. Kommerzielles Lizenz-/Update-System wird nicht angeboten.

## 50. Stabiler 1.0-Release und Wartungsbetrieb — Vorbereitung dokumentiert

Version `0.3.0`, `CHANGELOG.md` und Release-Gates im Betriebs-Runbook ergänzt. Build ist noch nicht reproduzierbar; Kompatibilitätsmatrix, Supportprozess, Sicherheitswartung und 1.0-Freigabe fehlen.
