# Konzept: Pass Captain

**Projekt:** Pass Captain  
**Repository:** `hastla007/wp-passmaster`  
**Stand:** 11. November 2026  
**Status:** Produkt- und Architekturkonzept  
**Zielgruppe:** WordPress-Shops mit WooCommerce, Veranstalter und Dienstleister mit Partnern

## 1. Ausgangslage

Das Repository enthält derzeit nur eine kurze README. Dieses Dokument legt daher das Produktziel, die Plugin-Grenzen, die Kernabläufe und eine priorisierte Umsetzung fest.

Pass Captain ist ein eigenständiges WordPress-/WooCommerce-Plugin zur Ausstellung und Verwaltung digitaler Tickets und Leistungspässe. Es erzeugt nach einem Kauf oder nach einer bestätigten Gutscheineinlösung individuelle QR-Tickets. Ein Ticket kann für einen Eventeintritt, eine einzelne Dienstleistung oder ein begrenztes Guthaben stehen, zum Beispiel für eine Fahrstunde oder eine Online-Navigationsstunde.

Pass Captain ist kein Terminplaner im MVP. Ein Pass kann einen Anspruch auf eine Leistung belegen. Terminwahl, Verfügbarkeiten und Umbuchungen sind ein späteres, separat planbares Modul.

## 2. Ziele

- Tickets nach bezahlten WooCommerce-Bestellungen ausstellen.
- Tickets nach erfolgreicher Einlösung eines Voucher-Captain-Gutscheins ausstellen.
- Pro gekaufter Stückzahl einzelne, eindeutige Tickets erstellen.
- Tickets per QR-Code sicher online prüfen und einlösen.
- Shopbetreiber können Produkte, Ticketregeln, Status und Verlauf administrieren.
- Partner können nur die ihnen zugewiesenen Tickets prüfen und einlösen.
- Ticket-Ausstellung und Einlösung sind bei Wiederholungen idempotent.
- Pass Captain funktioniert eigenständig. Voucher Captain erweitert es optional bei gemeinsamer Installation.
- Ein kundenspezifisches Connector-Plugin verbindet die ausgewählten Shopprodukte mit dem Bootsschule-/Kursplugin.

## 3. Produktgrenzen und Zusammenspiel

| Produkt | Zuständigkeit |
|---|---|
| WooCommerce | Produkt, Warenkorb, Kasse, Bestellung, Zahlungs- und Erstattungsstatus |
| Pass Captain | Passdefinition, einzelne Tickets, QR-Codes, Zustellung, Prüfung, Einlösung, Audit |
| Voucher Captain | Geschenkgutscheine, Rabattcodes und Gutschein-Guthaben |
| Native Integration Voucher ↔ Pass | Bei gemeinsamer Installation kann eine Gutscheineinlösung ein Pass-Captain-Ticket ausstellen |
| Bootsschule-Connector | Kundenspezifische Zuordnung zu Kursen, Kurszugängen und Kurslaufzeiten |

Voucher Captain und Pass Captain bleiben unabhängig voneinander installierbar. Für ihre direkte Integration gibt es kein zusätzliches Connector-Plugin. Die gegenseitige Anbindung erfolgt über eine dokumentierte, versionierte PHP-Schnittstelle und Events; direkte Abhängigkeiten auf interne Klassen oder Datenbanktabellen werden vermieden.

Der kundenspezifische Connector liegt separat und verbindet das Bootsschule-Plugin mit den ausgewählten Produktaktionen. Er kann für ein Produkt Kurszugang, Ticket oder beides konfigurieren. Diese Kundenlogik gehört nicht in den Pass-Captain-Kern.

## 4. Begriffe und Tickettypen

### Passdefinition

Eine Passdefinition beschreibt, was ein Produkt ausstellt: Art des Anspruchs, Menge/Verwendung, Gültigkeit, Empfängerangaben, Ticketvorlage und Einlösebedingungen.

### Ticketinstanz

Jede ausgestellte Einheit wird als eigene Ticketinstanz geführt. Bei Produktmenge 3 entstehen drei Tickets mit drei individuellen Codes und QR-Codes.

### Ticketarten im MVP

1. **Einmalpass:** einmalige Einlösung, etwa Eventeintritt oder eine Fahrstunde.
2. **Mehrfachpass:** begrenzte Einlösungen oder Guthaben, etwa drei Unterrichtsstunden.
3. **Terminpass:** ein Ticket berechtigt zu einer buchbaren Leistung; die Terminbuchung selbst ist zunächst extern oder manuell.

MVP-Tickets werden auf konkrete WooCommerce-Produkte oder Variationen abgebildet. Eine Weitergabe/Übertragung ist pro Passdefinition konfigurierbar.

### Lebenszyklus

- `issued`: ausgestellt und gültig
- `partially_redeemed`: teilweise verwendet, bei Mehrfachpässen
- `redeemed`: vollständig verwendet
- `expired`: Gültigkeit abgelaufen
- `revoked`: administrativ oder durch Erstattung widerrufen

Der Verlauf bleibt auch nach Widerruf oder Ablauf nachvollziehbar.

## 5. Hauptabläufe

### 5.1 Direktkauf eines Passprodukts

1. Betreiber aktiviert Pass Captain für ein WooCommerce-Produkt oder eine Variation.
2. Er wählt Passdefinition, Einlöseregeln, Gültigkeit, Empfängerfelder und Zustellvorlage.
3. WooCommerce erzeugt eine Bestellung. Pass Captain erstellt noch keine endgültigen Tickets, solange die Bestellung nicht als bezahlt gilt.
4. Nach Bestätigung des Zahlungseingangs erstellt Pass Captain je gekaufter Einheit genau eine Ticketinstanz.
5. Ticket und QR-Code erscheinen im WooCommerce-Kundenkonto und werden per E-Mail versendet.
6. Bei vollständig mit einem Gutschein bezahlten 0-Euro-Bestellungen erfolgt die Ausstellung nach dem bestätigten WooCommerce-Bestellabschluss, nicht bereits beim Hinzufügen zum Warenkorb.

Die Ausstellung läuft über stabile Bestell- und Positionsreferenzen, beispielsweise `order_id + order_item_id + unit_index`. Wiederholte WooCommerce-Ereignisse dürfen keine Duplikate erzeugen.

### 5.2 Gutschein wird zu einem Ticket

1. Kunde erhält einen Voucher-Captain-Gutschein.
2. In der Gutschein-Konfiguration ist festgelegt, dass die Einlösung ein bestimmtes Pass-Captain-Produkt oder eine Passdefinition ausstellt.
3. Voucher Captain reserviert den Gutschein für die Einlösung und ruft die Pass-Captain-Schnittstelle mit einer eindeutigen Einlöse-ID auf.
4. Pass Captain prüft Ziel und Daten und erstellt idempotent das Ticket.
5. Pass Captain bestätigt die Ticket-ID. Erst dann verbucht Voucher Captain die Gutschein-Einlösung endgültig.
6. Bei Fehlern wird der Vorgang mit derselben Einlöse-ID wiederholt. Es entstehen weder doppelte Tickets noch ein dauerhaft verbrauchter Gutschein ohne Leistung.

Die Ticket-Ausstellung muss auch dann korrekt funktionieren, wenn Voucher Captain oder Pass Captain bei der ersten Anfrage kurzzeitig nicht verfügbar ist. Es braucht einen gespeicherten, wiederholbaren Auftragsstatus und eine Admin-Ansicht für fehlgeschlagene Integrationsvorgänge.

### 5.3 QR-Code prüfen und Ticket einlösen

1. Partner meldet sich in einer Pass-Captain-Partneransicht an.
2. Partner scannt den QR-Code mit einem Smartphone oder gibt den Code manuell ein.
3. Pass Captain prüft serverseitig Ticketstatus, Gültigkeitszeitraum, Partnerberechtigung und verbleibende Nutzungen.
4. Bei erfolgreicher Prüfung wird die Einlösung atomar protokolliert und der Status bzw. Restwert aktualisiert.
5. Die Antwort zeigt nur erforderliche Informationen, zum Beispiel Passname, Gültigkeit, Nutzungen übrig und Ergebnis.
6. Wiederholtes Scannen eines bereits eingelösten Einmalpasses zeigt „bereits eingelöst“ und ändert den Status nicht erneut.

QR-Prüfung und Einlösung erfolgen online. Offline-Scanning ist im MVP ausgeschlossen, weil parallele Offline-Einlösungen nicht zuverlässig verhindert werden können.

### 5.4 Erstattung, Storno und Widerruf

- Ticketverhalten bei Bestellungserstattung ist pro Passdefinition einstellbar: widerrufen, nur ungenutzte Einheiten widerrufen oder manuell prüfen.
- Bei bereits eingelöstem Ticket bleibt der Auditverlauf erhalten; eine Rückerstattung löscht den Einlösungseintrag nicht.
- Voucher Captain und Pass Captain protokollieren bei Gutscheineinlösungen die verknüpfte Voucher- und Pass-Referenz.
- Statusänderungen durch Admins verlangen eine Begründung und erscheinen im Auditverlauf.

## 6. WooCommerce-Produktkonfiguration

Im Produkt- bzw. Variationseditor kann der Betreiber:

- Pass Captain für dieses Produkt aktivieren.
- Eine Passdefinition auswählen oder neu anlegen.
- festlegen, ob je Stück ein individuelles Ticket oder ein Ticket für die gesamte Position ausgestellt wird;
- Einmalpass oder Mehrfachpass mit erlaubter Nutzungszahl wählen;
- eine Gültigkeitsdauer, ein fixes Enddatum oder keine Ablaufzeit setzen;
- Übertragbarkeit und Empfängerfelder erlauben;
- Passname, Beschreibung, E-Mail-Vorlage und QR-Ticketvorlage wählen;
- den Auslöser für Gutscheinintegration bestimmen, wenn Voucher Captain aktiv ist;
- Verhalten bei Erstattung/Storno festlegen;
- bei Bedarf einen Partner oder eine Partnergruppe zuweisen.

Variationsprodukte können eigene Definitionen und Regeln verwenden. Ohne Pass-Captain-Konfiguration bleibt ein gewöhnliches WooCommerce-Produkt unverändert.

## 7. Kernfunktionen und Oberflächen

### Shopkunde

- Ticketliste in „Mein Konto“ mit Status, Gültigkeit und Download-/Druckansicht.
- E-Mail-Zustellung und erneuter Versand über das Konto.
- Empfängername und optionale Empfänger-E-Mail bei dafür konfigurierten Produkten.
- Übertragung, falls erlaubt: sicheren Transferablauf mit Bestätigung und Audit; kein editierbares Besitzerfeld.

### Betreiber/Admin

- Übersicht über Tickets nach Status, Produkt, Bestellnummer, Kunde, Partner und Gültigkeit.
- Ticketdetails, QR-Vorschau, Einlösungshistorie und verknüpfte Gutscheinreferenz.
- Erstellen, widerrufen, korrigieren und erneut zustellen mit Begründung und Audit.
- Wiederholbare Warteschlange für fehlgeschlagene Ticket-Ausstellungen.
- CSV-Export für operative Listen, mit rollenbasierter Begrenzung und Datenschutz.

### Partner

- Eigene Partnerrolle oder capability-basierte Rechte, keine Administratorrolle erforderlich.
- Partner sieht nur zugewiesene Tickets/Leistungen und deren Prüfergebnis.
- Scan-Ansicht für Mobilgeräte sowie manuelle Codeeingabe.
- Protokollierung des Partners, Zeitpunkts, Ergebnisses und optional der Einlösestelle.

## 8. Datenmodell und Integrationsvertrag

Für häufig veränderte Ticket- und Einlösedaten sind eigene, versionierte Tabellen zweckmäßig; Bestellinformationen werden über WooCommerce-CRUD-Objekte bezogen. WooCommerce empfiehlt die CRUD-API für Erweiterungen, damit Bestelldaten mit HPOS kompatibel bleiben. citeturn817217269search1turn817217269search14

### Entitäten

- **Passdefinition:** Zielprodukt/Variation, Art, Nutzungszahl, Gültigkeit, Empfänger-/Übertragungsregeln, Erstattungsregel.
- **Ticket:** interne ID, zufällige öffentliche Kennung, Token-Hash, Passdefinition, Bestell-/Positionsreferenz oder Gutschein-Einlöse-ID, Besitzer/Empfänger, Status, Gültigkeit.
- **Einlösung:** Ticket-ID, Nutzungseinheit, Partner, Zeitpunkt, Ergebnis, Idempotency-Key.
- **Audit-Ereignis:** Akteur, Aktion, Ticket-/Bestell-/Voucher-Referenz, Zeitpunkt, Begründung.
- **Integrationsjob:** Quelle, Idempotency-Key, Status, Wiederholungszähler, letzter Fehler, Ticketreferenz.

### Öffentliche PHP-Schnittstelle

Mindestens folgende Operationen sollten stabil und dokumentiert sein:

- `issue_pass(source, idempotency_key, target, recipient, validity)`
- `get_pass(pass_id)`
- `validate_pass(token, partner_id)`
- `redeem_pass(token, partner_id, idempotency_key)`
- `revoke_pass(pass_id, reason)`

Die konkrete Signatur wird vor Implementierung finalisiert. Schnittstellenantworten enthalten stabile Fehlercodes, keine internen Datenbankstrukturen.

### Events

- Ticket ausgestellt
- Ticket teilweise/vollständig eingelöst
- Ticket abgelaufen oder widerrufen
- Ausstellung fehlgeschlagen oder wiederholbar

Events sind Hinweise auf abgeschlossene Zustandsübergänge. Integrationen, die eine Leistung erzeugen, verwenden zusätzlich die idempotente PHP-Schnittstelle, damit Events keine doppelte Leistung auslösen.

## 9. QR- und Sicherheitsspezifikation

- QR enthält einen kryptografisch zufälligen, nicht erratbaren Token oder eine signierte, nicht personenbezogene Kennung.
- Token wird serverseitig sicher gespeichert, möglichst als Hash; QR-Endpunkt gibt keine personenbezogenen Daten preis.
- Tokenlänge und Entropie verhindern triviales Raten; Codes enthalten keine fortlaufenden Ticket-IDs.
- Einlösen erfordert Authentifizierung und Berechtigung für den Partner/Standort.
- Statusprüfung und Nutzungsabzug erfolgen atomar, damit zwei parallele Scans keinen doppelten Eintritt ermöglichen.
- Fehlerhafte Codeversuche werden rate-limited; Admins können ungewöhnliche Scanmuster einsehen.
- QR-URL und manuelle Kennung werden nicht in öffentlichen Logdateien ausgeschrieben.
- Partnerzugänge lassen sich widerrufen und einzelne Scans sind auditierbar.

QR-Tickets verhindern nicht, dass ein Screenshot weitergegeben wird. Das Produkt muss daher Einmalnutzung, Transferregeln und nötige Identitätsprüfung passend zum Einsatzzweck konfigurieren können.

## 10. Datenschutz und Betrieb

- Empfängername und -E-Mail nur erheben, wenn sie für Zustellung oder Ticketnutzung gebraucht werden.
- WordPress-Datenschutzauskunft und -löschung einbinden; steuer-/bestellrechtlich notwendige Referenzen getrennt von nicht erforderlichen Empfängerdaten behandeln.
- Lösch- und Aufbewahrungsfristen konfigurierbar machen; Ticket- und Einlösungshistorie nach Möglichkeit pseudonymisieren.
- E-Mails enthalten Ticket, QR-Code und Supporthinweise; erneuter Versand widerruft oder rotiert einen Code nicht stillschweigend.
- Admin- und Partneraktionen im Audit protokollieren, ohne unnötige Zahlungsdaten zu speichern.
- WP-Cron/Action Scheduler oder ein vergleichbares WooCommerce-kompatibles Verfahren für Ablauf und Wiederholungsjobs verwenden.

## 11. Kompatibilität und Qualität

- WooCommerce mit HPOS über CRUD-APIs unterstützen.
- Klassische Kasse und Warenkorb-/Kassen-Blöcke unterstützen. Block-Kompatibilität für zusätzliche Empfängerfelder separat implementieren; WooCommerce stellt eigene Erweiterungspunkte für Checkout-Blöcke bereit. citeturn817217269search20turn817217269search25
- Zahlung, Teil-/Vollerstat­tung, 0-Euro-Bestellung, Gastbestellung, Mengen > 1, variable Produkte und wiederholte Statushooks abdecken.
- Bei Änderungen der Bestellung dürfen bereits ausgestellte Tickets nicht unbemerkt dupliziert oder verändert werden.
- Minimalversionen von WordPress/PHP/WooCommerce festlegen, sobald die technische Basis gewählt ist.
- Übersetzbarkeit, Barrierefreiheit, E-Mail-Zustellbarkeit und Migrationen von Anfang an berücksichtigen.

## 12. Priorisierte Umsetzung

### P0 – Nutzbarer Kern

- Plugin-Bootstrap, Einstellungen, Datenbanktabellen und Migrationen.
- Passkonfiguration an WooCommerce-Produkten und Variationen.
- Idempotente Ticketausstellung bei bezahlter Bestellung, pro Position und Einheit.
- QR-Erzeugung, E-Mail, „Mein Konto“ und Admin-Ticketdetails.
- Online-Prüfen und Einmal-Einlösung mit Partnerberechtigung.
- Statusmodell, Auditverlauf, Ablauf und Admin-Widerruf.
- Erstattung/Storno-Regel und wiederholbare Fehlerbehandlung.
- HPOS-, Block-Checkout- und klassische-Kasse-Kompatibilität.
- Datenschutzauskunft/-löschung und grundlegende Rollenrechte.

### P1 – Native Voucher-Captain-Integration

- Versionierter PHP-Vertrag und Capability-Erkennung, wenn beide Plugins aktiv sind.
- Auswahl Passdefinition/Ziel im Voucher-Captain-Gutschein.
- Idempotente Ticket-Ausgabe bei bestätigter Gutschein-Einlösung.
- Reservieren, bestätigen, wiederholen und bei endgültigem Fehler freigeben.
- Voucher-ID ↔ Pass-ID im Audit und Admin sichtbar.
- Tests für Timeout, parallele Einlösung, erneuten Request und Teilausfall beider Plugins.

### P1 – Partnerbetrieb

- Partnerverwaltung und Zuordnung zu Passdefinitionen/Leistungen.
- Mobilfreundliche Scan-/Validierungsansicht.
- Standort-/Partnerfilter, manuelle Eingabe und Check-in-Verlauf.
- Begrenzte Partnerrollen und Supportfunktionen.

### P2 – Erweiterungen

- Terminbuchung, Verfügbarkeitskalender, Umbuchung und Storno.
- Mehrfachpässe mit Guthaben und Teilverbrauch.
- Ticketübertragung und Empfängerwechsel.
- Erinnerungen vor Termin oder Ablauf.
- Kampagnenstatistik und Partnerabrechnungsberichte.
- Export/API für externe Partner- oder Scanner-Apps.

## 13. Abnahmekriterien des MVP

- Ein WooCommerce-Passprodukt erzeugt nach erfolgreicher Zahlung für jede gekaufte Einheit genau ein Ticket.
- Eine Gutscheineinlösung erzeugt bei aktiver Voucher-Captain-Integration genau ein Ticket; Wiederholungen liefern dasselbe Ticket zurück.
- Der QR-Code funktioniert auf Mobilgeräten und enthält keine sichtbaren personenbezogenen Daten.
- Ein gültiger Partner kann ein Ticket prüfen und einlösen; ein unberechtigter Partner kann keine fremden Tickets einlösen.
- Ein Einmalpass kann auch bei parallelen Scans nur einmal eingelöst werden.
- Erstattungs- und Widerrufsregeln aktualisieren Ticketstatus nachvollziehbar und erhalten den Verlauf.
- HPOS und beide Checkout-Arten funktionieren ohne direkte Abhängigkeit auf WooCommerce-Order-Posts.
- Ist Voucher Captain nicht installiert, bleiben Kauf, Ausstellung und Einlösung von Pass Captain verfügbar.
- Die kundenspezifische Learnboating-Verknüpfung ist im separaten Connector umgesetzt und verändert nicht den generischen Pass-Captain-Kern.

## 14. Offene Produktentscheidungen vor Implementierung

1. Soll das Produkt zunächst stärker auf Eventeintritt, Dienstleistungspässe oder beide gleich ausgerichtet sein?
2. Dürfen Tickets übertragbar sein, und wenn ja, mit welchem Bestätigungsablauf?
3. Soll Ticket-Ausstellung nur nach Zahlung erfolgen oder für Betreiber auch manuell/als Gratispass möglich sein?
4. Für „Fahrstunde“: genügt ein einlösbarer Anspruch, oder muss Pass Captain direkt Terminbuchung und Umbuchung verwalten?
5. Welche Identitätsprüfung brauchen Partner beim Einlösen?
6. Soll der Partner-Scan im ersten Release ausschließlich online arbeiten?
7. Wie wird eine Teilrückerstattung auf mehrere Tickets einer Bestellung angewendet?

**Empfehlung für den ersten Release:** mit Online-Einmalpässen und QR-Entwertung starten, Gutscheinintegration als nächstes Paket direkt in den beiden Plugins ergänzen und Terminplanung erst nach einem konkreten Partnerprozess bauen.
