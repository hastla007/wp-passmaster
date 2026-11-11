# WP Pass Captain – nächste 10 Umsetzungsschritte

Der Plan führt vom leeren Konzept-Repository zu einem eigenständig nutzbaren MVP für Events, Tickets und Online-Einlass. WooCommerce und Voucher Captain bleiben optionale Integrationen; der Kern darf von keinem der beiden Plugins abhängen.

## 1. Plugin-Grundgerüst — begonnen

**Ergebnis:** Installierbarer Plugin-Einstieg mit Name, Textdomain, Namensraum, Versionskonstanten, Aktivierungs-/Deaktivierungshooks und eigener Admin-Navigation.

**Abnahme:** WordPress kann das Plugin erkennen und aktivieren; im Admin erscheint „Pass Captain“. Noch keine Eventdaten oder Fremdplugin-Abhängigkeiten.

## 2. Datenbankbasis und Berechtigungen

**Ergebnis:** Versionierte Schema-Migrationen für Kernobjekte, eigene Capabilities und Rollen, sichere Upgrade-Pfade sowie minimale Audit-Grundlage.

**Abnahme:** Aktivierung und Upgrade sind wiederholbar und verändern vorhandene Daten nicht unerwartet; operative Rollen erhalten nur ausdrücklich zugewiesene Eventrechte.

## 3. Events und Termine

**Ergebnis:** Event- und Terminmodell mit Status, Zeitzone, Ort, Kapazität und nachvollziehbaren Änderungen.

**Abnahme:** Ein Event kann mehrere unabhängige Termine haben; Zeiten werden mit der konfigurierten Zeitzone gespeichert und dargestellt.

## 4. Ticketkategorien und Kontingente

**Ergebnis:** Tickettypen mit Preis, Währung, Steuerklassifikation, Verkaufsfenster, Mengenlimit und Kontingent; zunächst ohne Sitzplan.

**Abnahme:** Verfügbarkeit und Ausverkauft-Status berücksichtigen aktive Reservierungen und Verkäufe; Steuerwerte sind konfigurierbar statt im Code festgeschrieben.

## 5. Öffentliche Eventseite und Auswahl

**Ergebnis:** Barrierearme Eventansicht mit Ticket-Auswahl, Shortcode und Gutenberg-Einbettung.

**Abnahme:** Besucher können Termine und verfügbare Kategorien mobil auswählen; Ausgabe funktioniert ohne WooCommerce.

## 6. Native Bestellung und Checkout

**Ergebnis:** Eigene Bestellungen und unveränderliche Positionen, Käuferdaten mit Datenminimierung sowie ein nachvollziehbarer Bestellstatus.

**Abnahme:** Ein Checkout kann ohne Fremdshop eine Bestellung anlegen; Wiederholungen erzeugen keine doppelten Bestellungen.

## 7. Reservierungen und Bestandskonkurrenz

**Ergebnis:** Zeitlich begrenzte, standardmäßig zehnminütige Bestandsreservierungen mit atomarer Kontingentprüfung und Ablaufverarbeitung.

**Abnahme:** Parallele Checkouts können Kontingente nicht überverkaufen; Abbruch und verspätete Zahlungsbestätigung folgen einem dokumentierten Wiederherstellungsablauf.

## 8. Zahlung und Ticketzustellung

**Ergebnis:** Zahlungsadapter-Vertrag, zuerst priorisierte PSP-Anbindung, signierte und idempotente Webhooks, Ticket-Ausstellung pro gekaufter Einheit, QR-Code, PDF und E-Mail.

**Abnahme:** Kartendaten werden nicht im Plugin gespeichert; doppelte oder verspätete Zahlungsereignisse geben keine Tickets doppelt aus.

## 9. Online-Check-in

**Ergebnis:** Mobilfreundliche Scanner-PWA mit Kamera und manueller Eingabe, Eventscoping, Rollenprüfung, atomarem Check-in und Auditprotokoll.

**Abnahme:** Gültig, bereits verwendet, widerrufen, falscher Termin und unbekannter Code werden unterscheidbar angezeigt; Doppel-Scans entwerten ein Ticket nicht erneut.

## 10. Datenschutz, Qualität und Release

**Ergebnis:** WordPress-Datenschutzexport/-löschung, konfigurierbare Aufbewahrung, Sicherheits- und Kompatibilitätsprüfungen, CI, reproduzierbarer Build und installierbares ZIP.

**Abnahme:** Aktivierung, Upgrade, Deaktivierung und Release-Artefakt sind auf unterstützten WordPress-/PHP-Versionen geprüft; Kernfunktionen laufen ohne WooCommerce und Voucher Captain.

## Danach: optionale Integrationen und Ausbau

1. Voucher-Captain-Bridge mit Quote, Reserve, Commit, Release und Refund.
2. WooCommerce-Adapter über CRUD-Schnittstellen und HPOS-Kompatibilität.
3. Sitzpläne, Wallet-Pässe, Buchungszeitfenster und Offline-Check-in mit sichtbarer Konfliktbehandlung.

## Namens- und Kompatibilitätsentscheidungen

- Produktname: **WP Pass Captain**; sichtbarer Admin-Menüname: **Pass Captain**.
- Textdomain und Plugin-Slug: `wp-pass-captain`.
- PHP-Namensraum: `PassCaptain\\`; technische Konstanten: Präfix `WPCAP_`.
- Der Repository-Name `wp-passmaster` bleibt vorerst bestehen.
- Vor Schema-Migrationen werden bestehende Konzeptbezeichner wie `TCAP` und `wp_etp_*` systematisch an die endgültigen Implementierungsnamen angepasst.
