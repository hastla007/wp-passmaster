# WP Pass Captain – Schritte 31–50

Diese Stufen folgen auf die Kern- und Integrationsausbaustufen 1–30. Sie erweitern Anpassbarkeit, Veranstalterbetrieb und Plattformreife; sie setzen die abgeschlossenen Sicherheits- und Datenmodelle der vorherigen Stufen voraus.

## 31. Event- und Ticketvorlagen

Themefähige Templates für Eventseiten, Ticketdarstellung und E-Mails bereitstellen. Abnahme: Betreiber können Layouts anpassen, ohne Ticketstatus, QR-Daten oder Sicherheitsprüfungen zu überschreiben.

## 32. Teilnehmerfelder und Formulare

Konfigurierbare Pflicht-/Optionsfelder pro Ticketart und Termin ergänzen. Abnahme: Einwilligung, Zweck, Feldzugriff, Export und Löschfrist sind pro Feld dokumentiert; sensible Daten bleiben standardmäßig deaktiviert.

## 33. Geplante Kommunikation

Erinnerungen, Änderungs- und Stornonachrichten mit Versandzeitpunkt, Zustellstatus und Wiederholung verwalten. Abnahme: Doppelte Queue-Jobs senden keine doppelten Nachrichten.

## 34. Teilnehmergruppen und Sammelbestellungen

Gruppenbuchungen, mehrere Käufer-/Teilnehmerrollen und Teamzuweisungen unterstützen. Abnahme: jeder Teilnehmer erhält nur sein Ticket; Käuferrechte und Teilnehmerrechte sind getrennt.

## 35. Mitgliedschaften und Saisonpässe

Mehrveranstaltungs-Pässe, Gültigkeitszeiträume und definierte Nutzungskontingente ergänzen. Abnahme: Passrechte werden pro Nutzung atomar verbraucht und können widerrufen werden.

## 36. Kampagnen und Preisregeln

Rabattaktionen, Gutscheincodes, Early-Bird- und Kontingentregeln mit klarer Priorität bereitstellen. Abnahme: Preisberechnung ist reproduzierbar, im Auftrag gespeichert und kann bei parallelen Checkouts nicht über ein Limit laufen.

## 37. Wartelisten und Platzangebote

Wartelisten für Events und buchbare Termine mit befristetem Angebot einführen. Abnahme: Reihenfolge, Annahmefrist, Kapazitätsfreigabe und Ablehnung sind protokolliert.

## 38. Veranstalterberichte

Umsatz-, Bestands-, Einlass-, Erstattungs- und Kampagnenberichte mit rollenbasierten Exporten ausbauen. Abnahme: Berichte verwenden dieselben abgegrenzten Finanz- und Teilnehmerdaten wie die Quellsysteme.

## 39. Öffentliche Entwickler-API

Stabile API-Schlüssel, Scopes, Rotation, Rate-Limits und versionierte Entwicklerdokumentation anbieten. Abnahme: Integrationen haben keinen Zugriff außerhalb freigegebener Aktionen und Eventbereiche.

## 40. WordPress-Multisite

Netzwerkaktivierung, getrennte Site-Daten und zentrale Administratorsteuerung spezifizieren. Abnahme: Bestellungen, Tickets, Optionen und Schlüssel eines Sites sind für andere Sites nicht abrufbar.

## 41. CRM-, Kalender- und Buchhaltungsintegrationen

Opt-in-Connectoren mit Datenminimierung, Mapping, Synchronisationsprotokoll und Wiederholung ergänzen. Abnahme: externe Systeme werden nur über dokumentierte Adapter angesprochen.

## 42. Übersetzte Eventinhalte

Mehrsprachige Eventseiten, E-Mails, Ticketvorlagen und Teilnehmerhinweise ermöglichen. Abnahme: Ticketdaten behalten eine kanonische Zeitzone und Währung; Käufer erhalten die gewählte Sprache.

## 43. Länderpakete und Steuerregeln

Versionierte, fachlich geprüfte Länder-/Steuerprofile konfigurieren. Abnahme: Änderungen gelten nur für neue Bestellungen; historische Bestell-Snapshots bleiben unverändert.

## 44. Hochlast- und Kapazitätsoptimierung

Indizes, Caching, Abfragepläne und Lasttests für große Kontingente und hohe Scanraten optimieren. Abnahme: Reservierungen und Einlass bleiben atomar, auch bei Spitzenlast.

## 45. Hintergrundverarbeitung und Wiederholung

Zuverlässige Jobverarbeitung für Zahlung, Mail, Exporte und Integrationen mit Backoff und Dead-Letter-Ansicht ergänzen. Abnahme: Ausfälle sind sichtbar und können idempotent erneut verarbeitet werden.

## 46. Backup und Notfallwiederherstellung

Schema-/Datenbackup, Restore-Anleitung und Wiederanlaufübungen dokumentieren. Abnahme: Tickets, Zahlungsverweise und Auditdaten lassen sich konsistent wiederherstellen.

## 47. Unabhängige Sicherheitsprüfung

Bedrohungsmodell, externe Codeprüfung, Penetrationstest und verantwortliche Meldestelle etablieren. Abnahme: kritische Befunde sind behoben und die Releasefreigabe dokumentiert.

## 48. Barrierefreiheitsprüfung

Checkout, Admin, E-Mails und Scanner mit automatisierten und manuellen Tests für Tastatur, Screenreader und Kontrast prüfen. Abnahme: bekannte Barrieren sind priorisiert, dokumentiert und freigegeben.

## 49. Erweiterungs- und Lizenzmodell

Falls kommerzielle Erweiterungen geplant sind, Update-, Lizenz-, Datenschutz- und Offline-Verhalten spezifizieren. Abnahme: Kernfunktionen bleiben ohne externen Lizenzserver nutzbar und Lizenzfehler beeinträchtigen keine bereits ausgegebenen Tickets.

## 50. Stabiler 1.0-Release und Wartungsbetrieb

Kompatibilitätsversprechen, Upgradefenster, Sicherheitsupdates, Supportkanäle und Deprecation-Regeln veröffentlichen. Abnahme: reproduzierbarer Build, Release-Gates, Wiederherstellungsplan und öffentliche Änderungsnotizen sind vollständig.
