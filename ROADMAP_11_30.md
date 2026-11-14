# Pass Captain – Schritte 11–30

Diese Ausbaustufen folgen auf den eigenständigen Event-, Verkaufs- und Einlasskern. Die Integrationen bleiben Adapter; Ticket- und Voucher-Datenhoheit werden nicht zusammengelegt.

## 11. Voucher-Captain-Bridge-Vertrag — umgesetzt (Vertrag)

Versionierten Servicevertrag mit Capability-Erkennung, Quote, Reservierung, Commit und Release veröffentlichen. Abnahme: Beide Plugins laufen weiterhin allein; unbekannte Bridge-Versionen werden sicher abgewiesen.

## 12. Voucher im Checkout — umgesetzt

Gutschein- und Guthabenprüfung mit Warenkorbkontext, Währung, Termin, Ticketarten und Stapelregeln einbinden. Abnahme: Keine direkten Tabellenzugriffe, parallele Nutzung wird beim Voucher-System reserviert.

## 13. Einlösung und Erstattung über die Bridge — umgesetzt (Checkout-Commit mit Wiederholung, Gutschein → Ticket, anteilige Erstattung)

Ticket-Ausgabe nach bestätigter Voucher-Einlösung und Rückabwicklung mit Positionszuordnung implementieren. Abnahme: Commit, Release, Refund und Wiederholung sind idempotent und revisionsfähig.

## 14. WooCommerce-Adapter: Bestellungen — Basis umgesetzt

Adapter-Schnittstelle und WooCommerce CRUD-Integration für Orders aufbauen. Abnahme: HPOS funktioniert ohne direkten Zugriff auf WooCommerce-Bestellungstabellen; Core bleibt ohne WooCommerce aktivierbar.

## 15. WooCommerce-Checkout-Oberflächen — teilweise umgesetzt

Classic Checkout und Checkout Blocks nach festgelegter Supportmatrix integrieren. Abnahme: Ticketkontingente, Statuswechsel, Storno und 0-Euro-Bestellungen erzeugen keine doppelten Tickets.

## 16. Zahlungsanbieter — Stripe, PayPal und Mollie umgesetzt

PSPs und Zahlungsmethoden anhand Zielland und Händlerkonten auswählen; Adapter für die priorisierten Anbieter implementieren. Abnahme: Signierte Webhooks, Idempotenz, verspätete Ereignisse und Teil-/Vollrefund sind abgedeckt.

## 17. Käuferbereich und Ticket-Selbstbedienung — Basis umgesetzt

Sichere Bestell- und Ticketansicht für angemeldete Käufer sowie zeitlich begrenzte Gastlinks ergänzen. Abnahme: Käufer sehen nur eigene Bestellungen; Links sind widerrufbar und enthalten keine fortlaufenden Datenbank-IDs.

## 18. Ticketänderung und Übertragung — umgesetzt (konfigurierbare Übertragungsregeln offen)

Teilnehmerangaben, Ticketnachsendung und kontrollierte Übertragung mit Token-Rotation anbieten. Abnahme: altes Token wird ungültig, Fristen und Eventregeln greifen serverseitig und jede Änderung wird protokolliert.

## 19. Eventänderungen und Benachrichtigungen — Absage und Verschiebung umgesetzt

Terminverschiebung, Eventabsage, Storno und Erstattung mit versionierten E-Mail-Vorlagen behandeln. Abnahme: Betroffene Käufer werden nachvollziehbar informiert; Zahlungs-, Bestell- und Ticketstatus bleiben konsistent.

## 20. Serien und wiederkehrende Termine — umgesetzt (Regeln, Admin, Kontingente, Preisregeln)

Serien als eigenständige Occurrences mit individuellen Preisen, Verkaufsfenstern, Kontingenten und Änderungsverlauf ausbauen. Abnahme: Änderungen an einem Termin verändern bereits verkaufte andere Termine nicht.

## 21. Sitzpläne — umgesetzt

Versionierte Sitzpläne lassen sich über REST anlegen und anzeigen; temporäre Sitzplatz-Holds sperren parallele Doppelbelegung und laufen ab. Inzwischen vorhanden: grafischer Admin-Editor, Platzwahl im nativen Checkout und Verkaufs-Commit bei Zahlung; WooCommerce weist Plätze automatisch zu.

## 22. Service-Zeitfenster und Buchungen — Grundfunktionen umgesetzt

Ressourcen, Zeitfenster und kapazitätsgesperrte, idempotente Buchungsholds sind über REST verfügbar. Puffer-Überlappungen, Umbuchungen, Wartelisten und Zahlungs-Commit fehlen.

## 23. Einlassregeln für Mehrtagesevents — umgesetzt, Mehrtagesabnahme offen

Einlassregeln pro Veranstaltungstag können per REST gesetzt werden. Einmaliger Eintritt, tägliche Nutzung und begrenzter Wiedereinlass werden beim Check-in unterschieden; Admin-Oberfläche „Einlassregeln“ und Check-out im Scanner sind inzwischen vorhanden.

## 24. Vor-Ort-Kasse und Druck — Kasse umgesetzt, Fiskalisierung offen

Ein eventberechtigter Nutzer kann über die POS-REST-Route einen bezahlten Verkauf auslösen; dabei werden dieselbe Bestandslogik und Ticketzustellung wie beim Onlineverkauf genutzt. Kassenoberfläche, Zahlungsarten vor Ort, Belege und Ticketdruck sind inzwischen vorhanden; fiskalische Belege (RKSV/TSE) fehlen.

## 25. Offline-Scanner — umgesetzt

Offline erfasste Codes werden lokal gepuffert und nach Wiederverbindung an einen Batch-Endpunkt gesendet; bereits genutzte Codes kommen als Konflikt zurück. Gerätezuweisung, signierte Offline-Pakete mit lokaler Prüfung und die Konfliktseite „Einlass-Konflikte“ sind inzwischen vorhanden; globale Einmaligkeit kann offline prinzipbedingt nicht garantiert werden.

## 26. Apple Wallet und Google Wallet — Pass-Erzeugung umgesetzt, Live-Abnahme offen

Versionierter Provider-Vertrag und Pass-Metadaten sind vorhanden. Apple-/Google-Pass-Erzeugung mit Aktualisierungen ist inzwischen vorhanden (`docs/WALLET.md`); Betreiberkonten, Zertifikate und Live-Abnahme fehlen. QR-Ticket bleibt verfügbar.

## 27. Rechnungen und Buchhaltung — umgesetzt, rechtliche Freigabe offen

Jährliche Rechnungsnummern werden transaktional vergeben; bezahlte Bestellungen können eine Rechnung mit Käufer- und Positionssnapshot erhalten. USt je Position, Rechnungs-PDF, Rechnungskorrekturen und Buchhaltungsexport sind inzwischen vorhanden; die rechtliche/steuerliche Freigabe je Land fehlt.

## 28. EU-Länder, Währungen und Übersetzungen — Währung/Locale-Grundlage umgesetzt

Currency-Filter und Locale-Helfer ergänzen die bestehenden Übersetzungsfunktionen. Länderprofile, Steuerregeln und länderspezifische Verbraucherinformationen fehlen.

## 29. Barrierefreiheit, Sicherheit und Betriebsüberwachung — teilweise umgesetzt

REST-Berechtigungen für POS/Verwaltung, Idempotency-Keys, Auditlogs und zugängliche Statusmeldungen sind erweitert. Eine manuelle Barrierefreiheits-, Mobil- und Sicherheitsexpertise-Prüfung ist noch offen.

## 30. Release und Wartung — Migration und CI vorhanden

Schema-Migration v3 und CI/ZIP-Build bleiben aktiv. Upgrade-/Rollback-/Restore-Abnahme mit echten WordPress-Datenbankständen und veröffentlichte Support-/Kompatibilitätsprozesse fehlen.

Die späteren Produkt- und Betriebsstufen stehen in [ROADMAP_31_50.md](ROADMAP_31_50.md).
