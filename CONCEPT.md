# Produkt- und Architekturkonzept: Pass Captain

**Projekt:** Pass Captain  
**Repository:** `hastla007/wp-passmaster`  
**Stand:** 11. November 2026  
**Status:** Überarbeitet nach Prüfung des Event-Ticketing-Umfangs  
**Zielgruppe:** Veranstalter, Kulturbetriebe, Vereine, Kongresse, Sportevents und Dienstleister im DACH-Raum

## 1. Produktvision und Positionierung

Pass Captain ist eine eigenständige Event-Ticketing- und Pass-Lösung für WordPress. Der Kern kann Events, Termine, Kontingente, Bestellungen, Zahlungen, Tickets und Einlass verwalten, ohne WooCommerce vorauszusetzen. WooCommerce und weitere Shops können über Commerce-Adapter angeschlossen werden.

Pass Captain stellt nach erfolgreichem Kauf oder einer bestätigten Gutscheineinlösung individuelle digitale Tickets mit QR-Code aus. Ein Pass kann für einen bestimmten Eventtermin gelten oder einen Anspruch auf eine Dienstleistung darstellen, etwa eine Fahrstunde oder eine Online-Navigationsstunde.

### Kernziele

- Eigene, indizierte Tabellen für Events, Bestellungen, Tickets, Reservierungen und Check-ins verwenden.
- Eine schnelle, mobil nutzbare Check-in-PWA ohne native App bereitstellen.
- WooCommerce optional unterstützen und dieselben öffentlichen Ticketdienste auch für andere Shop- und Buchungssysteme anbieten.
- Datenschutzminimierung und konfigurierbare DACH-Verkaufs-, Steuer- und Verbraucherinformationen unterstützen.
- Voucher Captain bei gemeinsamer Installation direkt integrieren.
- Kundenspezifische Verbindungen zum Bootsschule-/Kursplugin in einem separaten Connector halten.

Pass Captain stellt Konfigurationen und Nachweise bereit. Es verspricht keine pauschale Rechts- oder Steuerkonformität: Betreiber müssen die für ihr Land und ihr Angebot geltenden Einstellungen mit Rechts- und Steuerberatung festlegen.

## 2. Produktgrenzen und Zusammenspiel

| Baustein | Aufgabe |
|---|---|
| Pass Captain Core | Events, Terminserien, Ticketkategorien, Kontingente, Sitzplätze, Checkout, Bestellungen, Tickets, QR, Check-in, Rückerstattungsstatus und Berichte |
| Zahlungsadapter | Anbindung eines Zahlungsdienstleisters, Zahlungsstatus, signierte Webhooks, Erstattungsanfragen |
| Commerce-Adapter | Optionale Anbindung an WooCommerce oder andere Shop-/Buchungssysteme |
| Voucher Captain | Gutscheine, Geschenkguthaben und Rabattcodes |
| Native Voucher-/Pass-Integration | Ist beides installiert, kann die bestätigte Einlösung eines Gutscheins die Ticketausstellung auslösen |
| Bootsschule-Connector | Kundenspezifische Verbindung von Shopprodukten und Tickets mit Kursprodukten und Kurszugängen |

Voucher Captain und Pass Captain bleiben eigenständige Produkte. Ihre direkte Integration benötigt kein drittes Connector-Plugin. Ein gemeinsamer, versionierter PHP-Vertrag verbindet die Plugins; direkte Zugriffe auf interne Klassen oder Tabellen des jeweils anderen Plugins sind ausgeschlossen.

Der Bootsschule-Connector ist dagegen kundenspezifisch. Er kann festlegen, ob ein bestimmtes Produkt nach Kauf oder Gutscheineinlösung einen Kurszugang, ein Ticket oder beides auslöst. Diese Learnboating-Regeln kommen nicht in Pass Captain Core.

## 3. Event-, Termin- und Ticketverwaltung

### Events und Termine

- Ein Event kann einen oder mehrere einzelne Termine haben.
- Wiederkehrende Events erzeugen eigenständige Eventtermine mit eigenen Preisen, Kapazitäten, Verkaufsfenstern und Sitzplänen.
- Ein Termin speichert mindestens Zeitzone, Start/Ende, Ort, Status, Kapazität und Veranstalter.
- Änderungen oder Absagen eines Termins müssen Käufer benachrichtigen können und in einer Änderungshistorie sichtbar sein.

### Ticketkategorien

Pro Eventtermin sind beliebig viele Kategorien möglich, beispielsweise Standard, VIP, Ermäßigt und Early Bird. Jede Kategorie hat:

- Preis und steuerliche Produkt-/Leistungsklassifikation;
- Verkaufsstart und Verkaufsende;
- eigenes Kontingent und maximale Menge je Bestellung;
- einen Status wie geplant, im Verkauf, pausiert oder ausverkauft;
- optionale Teilnehmerfelder und Ausgabevorlage;
- optionale Zuordnung zu einem Sitzplatzbereich oder einer Passdefinition.

Ein Ausverkauft-Status berücksichtigt verkaufte und derzeit gehaltene Plätze. Abgelaufene Reservierungen geben Plätze wieder frei.

### Kapazität und Sitzplätze

Gesamtkontingente und Kontingente pro Ticketkategorie werden getrennt modelliert. Ein Sitzplatzplan unterstützt Reihen, Blöcke, Tische, Stehbereiche, Bühnenansicht und Barrierefreiheitskennzeichen.

Der Drag-and-Drop-Editor, Live-Status und Sitzplatzreservierung gehören zum erweiterten Umfang. Ein Event kann bereits ohne Sitzplan mit Kontingenten je Kategorie verkauft werden.

Sitzplatzdaten werden versioniert. Ein bereits verkauftes Ticket behält seinen belegten Platz auch dann, wenn der Saalplan später bearbeitet wird.

## 4. Eigenständiger Checkout und Reservierungen

Pass Captain stellt einen einbettbaren Checkout als Gutenberg-Block und Shortcode bereit. Der Checkout funktioniert mobil, ohne erzwungenen Seitenwechsel, und kann optionale Zusatzfelder pro Kategorie/Ticket erfassen.

### Reservierungssperre

- Beim Beginn des Checkout-Vorgangs werden gewählte Menge bzw. Sitzplätze temporär reserviert.
- Die Standarddauer beträgt zunächst 10 Minuten und wird konfigurierbar gehalten.
- Reservierungen besitzen einen eindeutigen Schlüssel und Ablaufzeitpunkt. Abgelaufene Reservierungen werden zuverlässig freigegeben.
- Kontingentprüfung und Reservierung sind atomar, damit parallele Käufe nicht überverkaufen.
- Die Reservierung wird erst bei sicher bestätigter Zahlung in einen Verkauf umgewandelt.
- Wenn eine Zahlungsbestätigung nach Ablauf des Holds eintrifft, wird der Auftrag anhand eines festgelegten Recovery-Prozesses behandelt: Platz wieder zuweisen, Erstattung auslösen oder zur manuellen Klärung markieren. Eine stille Überbuchung ist ausgeschlossen.

Zusatzfelder werden nach Event-/Ticketkategorie konfiguriert. Allergie- oder andere sensible Angaben sind standardmäßig nicht vorgesehen; wenn ein Betreiber sie aktiviert, braucht es einen klaren Zweck, beschränkten Zugriff und eine passende Löschfrist.

## 5. Zahlung, Bestellungen, Erstattungen und Buchhaltung

Pass Captain besitzt eine eigene Bestell- und Zahlungsabstraktion. Zahlungsdienstleister werden als Adapter angebunden. Für den ersten Zahlungsumfang sind Stripe und PayPal vorgesehen; die konkrete Verfügbarkeit von EPS, Klarna, SEPA, Apple Pay, Google Pay und weiteren Methoden hängt vom Anbieter, Händlerland, Währung, Konto und Transaktionsart ab. Methoden werden im Admin nur angezeigt, wenn sie für die jeweilige Integration verfügbar sind. Stripe dokumentiert diese Abhängigkeit nach Händlerkonto und Land. ([Stripe: unterstützte Währungen und Zahlungsmethoden](https://docs.stripe.com/currencies))

### Zahlungsanforderungen

- Kartendaten werden nicht von Pass Captain gespeichert oder verarbeitet.
- Gehostete oder tokenisierte Zahlungsoberflächen und signierte Webhooks nutzen.
- Webhook-Signaturen prüfen und Events idempotent verarbeiten.
- Zahlungsstatusmodell: angelegt, ausstehend, autorisiert, bezahlt, fehlgeschlagen, erstattet, teilweise erstattet und strittig.
- 3-D Secure/SCA, verzögerte Bankzahlungen und asynchrone Zahlungsbestätigungen berücksichtigen.
- Erstattungen werden über den jeweiligen Zahlungsadapter angefordert und nachvollziehbar protokolliert.
- Wechsel zwischen Payment- und Ticketstatus erfolgt über robuste, wiederholbare Jobs.

Apple Pay und Google Pay als **Zahlungsmethoden** sind von Apple Wallet und Google Wallet als **Ticketablage** zu unterscheiden.

### Rechnungen und Steuern

- Eine Bestellung enthält eigene unveränderliche Positionen mit Menge, Nettobetrag, Steuerklassifikation, Steuersatz, Steuerbetrag und Gesamtbetrag.
- Gemischte Steuerklassen (zum Beispiel Eintritt und Catering/Merchandise) werden pro Position berechnet und mit nachvollziehbarer Rundung summiert.
- Steuersätze werden nicht fest in PHP codiert. Konfigurierbare Länder-/Region-/Leistungsklassifikationen und versionierte Steuerregeln bilden die technische Grundlage.
- Steuerregeln für Österreich, Deutschland, Schweiz, OSS, steuerbefreite Unternehmen und Kleinunternehmer sind vor Produktivbetrieb fachlich zu prüfen.
- Fortlaufende Rechnungsnummern müssen auch bei parallelen Bestellungen eindeutig und unveränderlich sein. Storno-/Korrekturrechnungen und Teilrückzahlungen brauchen eigene Buchungsbelege.
- PDF-Rechnungen und Buchhaltungsexporte sind als eigenständiges Modul geplant. Rechnungsanforderungen werden vor Umsetzung mit Steuerberatung für Zielländer und Kundentypen spezifiziert.

Pass Captain kann technische Steuerdaten und Belege führen. Es sollte sich nicht ohne fachliche Freigabe als „steuerkonform für DACH“ bewerben.

## 6. Tickets, QR und Wallets

### PDF und Zustellung

Jedes Ticket erhält eine individuelle Darstellung mit Event, Termin, Kategorie, Teilnehmer, Sitzplatz, Zugangshinweisen und QR-Code. Druck-PDFs, E-Mail-Vorlagen und Downloads in der Teilnehmeransicht nutzen dieselbe Ticketreferenz.

### Fälschungssicherheit

- Kein Ticket-Token wird aus Bestellnummer, E-Mail, Event-ID oder Teilnehmerdaten abgeleitet.
- QR enthält ein zufälliges, nicht erratbares Token mit hoher Entropie oder ein geprüft signiertes Token; personenbezogene Daten bleiben serverseitig.
- Serverseitig wird nur ein Token-Hash gespeichert, wenn die Ticketzustellung keine spätere Rekonstruktion des Codes benötigt. Wird Code-Rotation benötigt, wird ein neues Token ausgegeben und das alte widerrufen.
- Das öffentlich prüfbare Ticket verwendet eine zufällige Kennung, nicht die fortlaufende interne Datenbank-ID.
- QR-Prüfung zeigt keine persönlichen Daten ohne passende Partnerberechtigung.

Ein QR kann kopiert oder als Screenshot geteilt werden. Einmalige Einlösung, kontrollierte Übertragung und bei Bedarf ein Identitätsabgleich begrenzen diesen Missbrauch; sie verhindern ihn nicht generell.

### Apple Wallet und Google Wallet

Wallet-Integration ist eine spätere Ausbaustufe. Apple Wallet benötigt Pass-Type-Identifier und ein dazugehöriges Signing-Zertifikat. Google Wallet benötigt ein Issuer-Konto und Wallet-API-Integration. ([Apple Wallet Zertifikate](https://developer.apple.com/help/account/capabilities/create-wallet-identifiers-and-certificates), [Google Wallet Event Tickets](https://developers.google.com/wallet/tickets/events))

Tickets sollen nach Widerruf oder Terminänderung aktualisierbar sein, wenn die jeweilige Plattform und Betreiberkonfiguration das erlauben. Lokale PDF-/QR-Tickets bleiben immer verfügbar, auch wenn Wallet-Anbieter nicht eingerichtet sind.

## 7. Check-in-PWA und Offline-Verhalten

Die PWA unter einer konfigurierbaren Route wie `/event-checkin` bietet Login für Einlasspersonal, Kamera-QR-Scan und manuelle Codeeingabe. Rollen- und Eventzuweisungen begrenzen, welche Tickets ein Mitarbeiter prüfen oder entwerten darf.

### Online-Modus

- Echtzeitprüfung beim Pass-Captain-Server.
- Ergebnis: gültig, bereits eingecheckt, widerrufen, falscher Termin, noch nicht gültig oder unbekannt.
- Bei erfolgreicher Einlösung erfolgt Statusänderung atomar. Ein zweiter gleichzeitiger Scan kann den Check-in nicht duplizieren.
- Logs enthalten Mitarbeiter, Gerät/Scannerkennung, Eventtermin, Zeitpunkt und Ergebnis.

### Offline-Modus

Offline-Check-in wird als spätere, optionale Funktion behandelt. IndexedDB kann lokal Prüfdaten und Scan-Ereignisse halten; nach Wiederverbindung werden sie synchronisiert. Zwei voneinander getrennte Offline-Geräte können dasselbe Ticket jedoch beide als gültig ansehen. Deshalb kann ein Offline-Modus globale Einmaligkeit nicht garantieren.

Für offlinefähige Einlässe müssen Veranstalter bewusst eine Konfliktregel, Gerätezuweisung, Zeitfenster und maximalen Datenumfang konfigurieren. Hochrisiko- oder streng kapazitätsbegrenzte Einlässe verwenden Online-Prüfung. Offline-Sync darf Konflikte nicht verbergen; doppelte Scans werden markiert und im Admin nachverfolgt.

## 8. Datenmodell und Architektur

Pass Captain verwendet eigene, versionierte und indizierte Tabellen für volumenstarke Event- und Ticketdaten. WordPress-Core-Tabellen bleiben für Benutzer, Einstellungen und Plugin-Administration zuständig.

### Vorgeschlagene Tabellen

| Tabelle | Zweck |
|---|---|
| `wp_etp_events` | Eventstammdaten und Einstellungen |
| `wp_etp_occurrences` | Einzeltermine/Serieninstanzen, Ort, Start/Ende, Zeitzone |
| `wp_etp_ticket_types` | Ticketkategorien, Preis, Steuerklassifikation, Verkauf und Kontingent |
| `wp_etp_seatmaps` | Versionierte Saalpläne; Layout als JSON, Plätze/Belegung referenziert |
| `wp_etp_orders` | Eigenständige Pass-Captain-Bestellungen und Status |
| `wp_etp_order_items` | Unveränderliche Warenkorb-/Orderpositionen, Steuer- und Preissnapshot |
| `wp_etp_payments` | PSP-Referenzen, Status und Webhook-Verarbeitung; keine Kartendaten |
| `wp_etp_reservations` | Temporäre Holds für Mengen und Sitzplätze mit Ablaufzeit |
| `wp_etp_tickets` | Öffentliche Kennung, Token-Hash, Termin, Kategorie, Sitzplatz, Status |
| `wp_etp_attendees` | Minimale Teilnehmerdaten und Zusatzfelder, getrennt von Zahl-/Finanzdaten |
| `wp_etp_checkin_logs` | Append-only Check-in- und Scanereignisse |
| `wp_etp_audit_logs` | Admin-, Partner-, Zahlungs- und Integrationsänderungen |
| `wp_etp_integration_jobs` | Wiederholbare Voucher-, PSP-, Mail- und Adaptervorgänge |

Bei WooCommerce-Bestellungen speichert Pass Captain nur die externe Bestell-/Positionsreferenz und notwendige Snapshots. Es kopiert keine komplette WooCommerce-Bestellung und greift nicht direkt auf Bestelltabellen zu; WooCommerce-Zugriff erfolgt über CRUD-Schnittstellen, die HPOS berücksichtigen. ([WooCommerce HPOS Recipe Book](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/))

### Tech-Stack und Paketwahl

- PHP 8.2+ als Startannahme, Composer und PSR-4; finale Mindestversion erst nach Abgleich mit WordPress- und Hostingbasis festlegen.
- WordPress REST API unter `/wp-json/pass-captain/v1/` für PWA, Admin und Adapter.
- React/`@wordpress/scripts` für Gutenberg-Blöcke und Admin-Oberflächen.
- Bibliotheken für QR, PDF und Wallet erst nach Lizenz-, Wartungs-, Sicherheits- und PHP-Kompatibilitätsprüfung auswählen. `endroid/qr-code`, mPDF und PassKit-Pakete sind Kandidaten, keine Architekturvoraussetzung.
- Secrets möglichst aus Umgebungsvariablen oder `wp-config.php` beziehen; keine Klartext-PSP-Schlüssel in öffentlich zugänglichen Logs oder Exporten.

## 9. Öffentliche Dienste und Integrationsvertrag

Commerce- und Zahlungsadapter greifen auf stabile Services zu, keine internen Tabellen. Kernoperationen:

- `create_order(source, items, customer, idempotency_key)`
- `reserve_inventory(occurrence_id, ticket_type_id, quantity, seat_ids, expires_at)`
- `confirm_payment(order_id, provider_reference, idempotency_key)`
- `issue_tickets(order_id, idempotency_key)`
- `validate_ticket(token, event_id, partner_id)`
- `check_in_ticket(token, event_id, partner_id, idempotency_key)`
- `refund_order(order_id, amount, reason)`
- `revoke_ticket(ticket_id, reason)`

Schnittstellen antworten mit stabilen Fehlercodes und dokumentierten Datenformaten. Idempotency-Keys und externe Quellen werden gespeichert; parallele oder wiederholte Webhooks ändern keine Zustände mehrfach.

Voucher Captain ruft `issue_tickets()` nach bestätigter Gutscheineinlösung mit Voucher-ID, Redeem-ID und definiertem Ziel auf. Ticket-Ausstellung bestätigt eine Ticketreferenz. Erst nach dieser Bestätigung schließt Voucher Captain die Einlösung ab. Ein fehlgeschlagener Aufruf bleibt wiederholbar.

Ereignisse wie `pass_captain_ticket_issued`, `pass_captain_ticket_checked_in`, `pass_captain_ticket_revoked` und `pass_captain_order_refunded` sind für Benachrichtigungen und Connectoren vorgesehen. Sie ersetzen keine idempotente Serviceoperation.

## 10. Recht, Steuern und Datenschutz (DACH)

### Verbraucherinformationen und Rücktritt

Der Checkout muss Eventdatum, Veranstalter, Preis, Ticketmerkmale und die anwendbaren Verbraucherinformationen korrekt darstellen und die übermittelten Fassungen nachvollziehbar versionieren. Für bestimmte Dienstleistungen im Zusammenhang mit Freizeitbetätigungen kann bei vertraglich festgelegtem Zeitpunkt/Zeitraum eine gesetzliche Ausnahme vom Rücktrittsrecht gelten. Die Anwendbarkeit hängt von Leistung und Vertragsgestaltung ab; eine pauschale Checkbox im Checkout schafft nicht automatisch die rechtliche Ausnahme. Österreichisches FAGG § 18 Abs. 1 Z 10 und deutsches BGB § 312g Abs. 2 Nr. 9 enthalten solche Ausnahmen. Jede Text-/Checkbox-Vorlage und Regelentscheidung muss juristisch geprüft und vom Betreiber konfiguriert werden. ([RIS: FAGG § 18](https://www.ris.bka.gv.at/GeltendeFassung.wxe?Abfrage=Bundesnormen&Gesetzesnummer=20008847), [Gesetze im Internet: BGB § 312g](https://www.gesetze-im-internet.de/bgb/__312g.html))

### Steuer- und Rechnungsdaten

Pass Captain verwaltet Preise und Steuerdaten pro Position, fixiert den zum Bestellzeitpunkt verwendeten Steuersatz und speichert erforderliche Rechnungsbelege. Es setzt keine Steuersätze oder Kleinunternehmerregeln als universelle DACH-Defaults. Veranstalter/Steuerberatung konfigurieren Leistungskategorien, Lieferorte, Ermäßigungen und Korrekturen. OSS, grenzüberschreitende Leistungen und gemischte Steuersätze sind vor einer Produktivfreigabe fachlich zu validieren.

### Datenschutz

- Teilnehmerdaten und Finanz-/Rechnungsdaten werden getrennt gehalten und rollenbasiert zugänglich gemacht.
- Nur erforderliche Felder werden erhoben. Sensible Angaben sind deaktiviert, bis Zweck und Zugriff geregelt sind.
- WordPress-Auskunfts- und Löschfunktionen werden unterstützt.
- Keine pauschale automatische Löschung aller Teilnehmerdaten nach einer frei gesetzten Zahl von Tagen. Betreiber legen Fristen je Datenklasse fest, berücksichtigen Rechtsgrundlagen, Aufbewahrungspflichten, Streitfälle und laufende Verträge.
- Nach Ablauf der jeweiligen Frist werden Teilnehmerdaten gelöscht oder irreversibel anonymisiert; Finanzbelege und unveränderliche Auditnachweise können separat mit möglichst wenig Personenbezug erhalten bleiben.
- Die DSGVO verlangt Datenminimierung und Speicherbegrenzung, zugleich ist eine dokumentierte Grundlage für Aufbewahrung/Löschung nötig. ([DSGVO Art. 5 und 17](https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng/))
- Lokale Skripte und Schriftarten sind Standard, soweit keine erforderlichen externen Dienste aktiv sind. PSP- und Wallet-Anbindungen werden transparent ausgewiesen.
- Datenschutztexte, Einwilligungsfelder und Fristen sind Werkzeuge für Betreiber, kein pauschaler Konformitätsnachweis.

## 11. Sicherheit, Performance und Kompatibilität

- Nonces für browserbasierte WordPress-Sitzungen plus capability-Prüfung; API-Tokens für Partner sind widerrufbar und eng begrenzt.
- Rate-Limiting auf Ticketprüfung, Login und Check-in-Endpunkten.
- Eingaben validieren, Ausgaben escapen, SQL vorbereiten; PII und Tokens aus Logs entfernen.
- Datenbanktabellen mit Indizes für Eventtermin, Ticketstatus, Kategorie, Order-Referenz und Check-in-Abfrage versehen.
- Ticket- und Seat-Status atomar aktualisieren; Überbuchungen und parallele Doppel-Scans verhindern.
- WooCommerce-Integration verwendet offizielle CRUD-APIs und erklärt HPOS-Kompatibilität. WooCommerce-Checkout-Blöcke und klassische Kasse sind getrennte Adapter-Integrationspunkte. ([HPOS Recipe Book](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/), [Checkout Blocks Extensibility](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/))
- WooCommerce ist keine Installationsvoraussetzung für den nativen Checkout.
- Migrationen, Backups, Datenbereinigung und Wiederherstellung großer Tabellen sind Teil des Betriebsentwurfs.

## 12. Priorisierte Umsetzung

### P0 – Eigenständiger Verkauf und Online-Einlass

- Plugin-Bootstrap, Rollen/Rechte, Adminnavigation, REST-Grundlage und Schema-Migrationen.
- Events, einzelne Termine/Occurrences, Ticketkategorien, Verkaufszeiträume und Kapazitäten.
- Native Checkout- und Bestellobjekte; grundlegende Zahlungsadapter-Schnittstelle.
- PSP-Webhooks, Idempotenz und robuste Statusmaschine; Kartenangaben bleiben beim PSP.
- Reservierungssperren für Inventar, zunächst ohne grafischen Sitzplan.
- Einzelne QR-Tickets, E-Mail/PDF-Ausgabe, Teilnehmeransicht und Adminsuche.
- Browser-PWA für Online-Prüfung und Einmal-Check-in.
- Rückerstattungs-/Widerrufsstatus, Check-in-Historie und Datenschutzfunktionen.
- WooCommerce-Adapter als optionales Modul mit HPOS-Unterstützung.

### P1 – DACH-Verkauf und native Voucher-Integration

- Steuerklassifikationen pro Position, Rechnungs-/Korrekturbelegmodell und Datenexport.
- Juristisch konfigurierbare Checkout-Informationen und Nachweis der gezeigten Version.
- Native Integration mit Voucher Captain: Voucher-Einlösung, Ticket-Ausgabe und sichere Wiederholung.
- Ticketklassen, Empfängerfelder, Rabatte und Konflikt-/Stapelkonditionen.
- Partnerverwaltung, Event-/Standortzuweisung und Check-in-Berichte.
- Stripe- und PayPal-Adapter konkretisieren; Zahlungsmethoden nach PSP-Fähigkeit ein-/ausschalten.

### P2 – Sitzplan, Wallet und vertiefte Betriebsfunktionen

- Grafischer Seat-Map-Editor und Live-Sitzplatzstatus.
- Apple Wallet und Google Wallet.
- Mehrfach-/Servicepässe, sichere Übertragung und optionaler Terminbuchungsconnector.
- Erweiterte Exporte, Umsatz- und Check-in-Auswertung, Erinnerungs-E-Mails.

### P3 – Offline und Erweiterungen

- Offline-PWA mit expliziten Konfliktregeln, Gerätezuteilung und nachgelagerter Synchronisation.
- Offlinebetrieb wird nur für Veranstalter freigegeben, die das verbleibende Risiko doppelter Scans akzeptieren.
- Externe Scanner-Apps, API-Zugang und Partnerabrechnungen.
- Serien- und Wiederholungstermine mit erweiterten Preis-/Kontingentregeln.

## 13. MVP-Abnahmekriterien

- Betreiber kann ein Event mit mehreren Terminen, Ticketkategorien, Preisfenstern und Kontingenten ohne WooCommerce anlegen.
- Native Bestellung und bestätigte Zahlung erzeugen je gekaufter Einheit genau ein Ticket.
- Wiederholte oder parallele Zahlungs-Webhooks erzeugen weder doppelte Tickets noch doppelte Buchungen.
- Holds verhindern bei parallelen Checkouts den Überverkauf; Ablauf gibt den Bestand zuverlässig frei.
- Online-PWA prüft und entwertet Tickets atomar; ein Einmal-Ticket kann nicht doppelt eingelöst werden.
- Teilnehmer sieht seine Tickets; Partner sieht nur ihm zugewiesene Eventdaten.
- Erstattung und Storno ändern den Ticketstatus nach konfigurierbarer Regel und erhalten den Auditverlauf.
- WooCommerce ist optional; falls aktiv, bleiben Bestellzugriffe mit HPOS und klassischen Bestellungen kompatibel.
- Die Voucher-Captain-Integration erzeugt Tickets erst nach bestätigter Einlösung und bleibt bei Timeout wiederholbar.
- Privacy-Export/-Löschung und definierte Aufbewahrungsregeln für Teilnehmerdaten sind bedienbar.
- Offline-Scanning, Seat Maps, Wallet-Pässe und Rechnungs-PDFs sind klar als spätere Module abgegrenzt, soweit sie nicht Bestandteil des ersten Release-Vertrags sind.

## 14. Noch zu entscheidende Produktfragen

1. Welche Länder sollen zum Start wirklich unterstützt werden: Österreich/Deutschland oder auch Schweiz?
2. Ist der erste Marktfokus klassische Eventtickets, Vereins-/Sportveranstaltungen oder Servicepässe?
3. Soll P0 mit einem Zahlungsdienstleister starten oder ist Checkout zunächst nur mit externem PSP/Manual-Payment möglich?
4. Wie viel Umfang erhält die erste Rechnungsfunktion: Datenexport, einfache Rechnung oder vollständige Rechnung/Korrekturbeleg?
5. Muss der Sitzplan im ersten Verkaufsrelease enthalten sein, oder reichen zunächst Kategorien und Kontingente?
6. Welche Teilnehmerfelder sind fachlich nötig, und welche sensiblen Felder werden ausgeschlossen?
7. Soll Offline-Check-in irgendwann harte Einmaligkeit bieten? Das ist bei mehreren getrennten Offline-Geräten technisch nicht zuverlässig garantierbar.
8. Terminbuchung für Fahrstunden: Bestandteil von Pass Captain, oder separater Buchungsdienst, den ein Pass lediglich freischaltet?

**Empfehlung:** P0 als eigenständigen Eventverkauf mit Ticketkategorien und Kontingenten, nativer Online-Zahlung, QR-PDFs und Online-PWA planen. Seat-Map, Wallet, vollständige Rechnungs-/Steuerlogik und Offline-Check-in sind größere Module; ihren Aufwand vor Festlegung des Releaseumfangs separat schätzen.

## Quellen zur technischen und rechtlichen Validierung

- [Österreichisches FAGG, § 18, RIS](https://www.ris.bka.gv.at/GeltendeFassung.wxe?Abfrage=Bundesnormen&Gesetzesnummer=20008847)
- [Deutsches BGB, § 312g](https://www.gesetze-im-internet.de/bgb/__312g.html)
- [DSGVO, Verordnung (EU) 2016/679](https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng/)
- [WooCommerce HPOS Extension Recipe Book](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/)
- [WooCommerce Cart and Checkout Blocks](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/)
- [Apple Wallet Certificates](https://developer.apple.com/help/account/capabilities/create-wallet-identifiers-and-certificates)
- [Google Wallet Event Tickets](https://developers.google.com/wallet/tickets/events)
- [Stripe Supported Currencies and Payment Methods](https://docs.stripe.com/currencies)
