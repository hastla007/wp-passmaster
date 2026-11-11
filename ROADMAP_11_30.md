# WP Pass Captain – Schritte 11–30

Diese Ausbaustufen folgen auf den eigenständigen Event-, Verkaufs- und Einlasskern. Die Integrationen bleiben Adapter; Ticket- und Voucher-Datenhoheit werden nicht zusammengelegt.

## 11. Voucher-Captain-Bridge-Vertrag

Versionierten Servicevertrag mit Capability-Erkennung, Quote, Reservierung, Commit und Release veröffentlichen. Abnahme: Beide Plugins laufen weiterhin allein; unbekannte Bridge-Versionen werden sicher abgewiesen.

## 12. Voucher im Checkout

Gutschein- und Guthabenprüfung mit Warenkorbkontext, Währung, Termin, Ticketarten und Stapelregeln einbinden. Abnahme: Keine direkten Tabellenzugriffe, parallele Nutzung wird beim Voucher-System reserviert.

## 13. Einlösung und Erstattung über die Bridge

Ticket-Ausgabe nach bestätigter Voucher-Einlösung und Rückabwicklung mit Positionszuordnung implementieren. Abnahme: Commit, Release, Refund und Wiederholung sind idempotent und revisionsfähig.

## 14. WooCommerce-Adapter: Bestellungen

Adapter-Schnittstelle und WooCommerce CRUD-Integration für Orders aufbauen. Abnahme: HPOS funktioniert ohne direkten Zugriff auf WooCommerce-Bestellungstabellen; Core bleibt ohne WooCommerce aktivierbar.

## 15. WooCommerce-Checkout-Oberflächen

Classic Checkout und Checkout Blocks nach festgelegter Supportmatrix integrieren. Abnahme: Ticketkontingente, Statuswechsel, Storno und 0-Euro-Bestellungen erzeugen keine doppelten Tickets.

## 16. Zahlungsanbieter

PSPs und Zahlungsmethoden anhand Zielland und Händlerkonten auswählen; Adapter für die priorisierten Anbieter implementieren. Abnahme: Signierte Webhooks, Idempotenz, verspätete Ereignisse und Teil-/Vollrefund sind abgedeckt.

## 17. Käuferbereich und Ticket-Selbstbedienung

Sichere Bestell- und Ticketansicht für angemeldete Käufer sowie zeitlich begrenzte Gastlinks ergänzen. Abnahme: Käufer sehen nur eigene Bestellungen; Links sind widerrufbar und enthalten keine fortlaufenden Datenbank-IDs.

## 18. Ticketänderung und Übertragung

Teilnehmerangaben, Ticketnachsendung und kontrollierte Übertragung mit Token-Rotation anbieten. Abnahme: altes Token wird ungültig, Fristen und Eventregeln greifen serverseitig und jede Änderung wird protokolliert.

## 19. Eventänderungen und Benachrichtigungen

Terminverschiebung, Eventabsage, Storno und Erstattung mit versionierten E-Mail-Vorlagen behandeln. Abnahme: Betroffene Käufer werden nachvollziehbar informiert; Zahlungs-, Bestell- und Ticketstatus bleiben konsistent.

## 20. Serien und wiederkehrende Termine

Serien als eigenständige Occurrences mit individuellen Preisen, Verkaufsfenstern, Kontingenten und Änderungsverlauf ausbauen. Abnahme: Änderungen an einem Termin verändern bereits verkaufte andere Termine nicht.

## 21. Sitzpläne

Versionierte Saalpläne, Bereiche, Plätze und atomare Sitzplatz-Holds ergänzen. Abnahme: parallele Käufer können denselben Platz nicht erhalten; verkaufte Plätze behalten ihre alte Planversion.

## 22. Service-Zeitfenster und Buchungen

Terminslots, Ressourcen, Puffer, Verfügbarkeit, Umbuchungen und Wartelisten für Dienstleistungen einführen. Abnahme: gleichzeitige Reservierungen kollidieren nicht; Service-Pässe behalten eigene Nutzungsregeln.

## 23. Einlassregeln für Mehrtagesevents

Einmaligen Eintritt, tägliche Nutzung, Wiedereinlass und Check-out als explizite Admission Policies modellieren. Abnahme: Status und Berechtigungen sind je Veranstaltungstag getrennt und nachvollziehbar.

## 24. Vor-Ort-Kasse und Druck

POS/Box Office mit Verkäufen, manueller Ausgabe, Kontingentabgleich, Belegen und Badge-/Listen-Druck ergänzen. Abnahme: Online- und Vor-Ort-Verkäufe verwenden dieselbe atomare Bestandslogik.

## 25. Offline-Scanner

Gerätezuweisung, lokale begrenzte Prüfdaten, Synchronisierung und Konfliktklärung implementieren. Abnahme: doppelte Einlösung auf getrennten Offline-Geräten wird markiert; Offlinebetrieb verspricht keine globale Einmaligkeit.

## 26. Apple Wallet und Google Wallet

Wallet-Ausgabe mit Betreiberkonten, Signaturzertifikaten, Aktualisierung und Widerruf ergänzen. Abnahme: PDF-/QR-Tickets bleiben verfügbar, wenn Wallet-Anbieter nicht eingerichtet sind.

## 27. Rechnungen und Buchhaltung

Positionsbezogene Steuer-Snapshots, Belege, Korrekturbelege und Buchhaltungsexporte nach fachlicher Spezifikation entwickeln. Abnahme: fortlaufende Nummern sind parallel sicher; Erstattungen und gemischte Steuersätze sind prüfbar.

## 28. EU-Länder, Währungen und Übersetzungen

Länder-/Währungskonfiguration, Übersetzungen und länderspezifische Verbraucherinformationen erweitern. Abnahme: keine universellen Steuer- oder Rechtsversprechen; jedes unterstützte Szenario wird fachlich freigegeben.

## 29. Barrierefreiheit, Sicherheit und Betriebsüberwachung

Admin, Checkout und Scanner barrierefrei prüfen; Rollen, Rate-Limits, Sicherheitsprotokolle, Update- und Fehlerdiagnose härten. Abnahme: Penetrations-/Berechtigungstests und manuelle Mobil-/Desktop-Abnahme sind dokumentiert.

## 30. Release und Wartung

Migrationspfade, Kompatibilitätsmatrix, Übersetzungen, Supportprozesse, Reproduzierbarkeit und Releaseartefakte finalisieren. Abnahme: Upgrade aus unterstützten Vorversionen, Rollback-/Wiederherstellungsabläufe, Backups und Release-Gates sind dokumentiert.
