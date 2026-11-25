# Funktions- und Abnahmematrix

Stand: 16.11.2026, Version 1.5.0 plus unveröffentlichte Änderungen laut `CHANGELOG.md`
(A691, Z16-09, PASS-FE-25). Wird mit jedem Release fortgeschrieben (Release-Gate in [RELEASE.md](RELEASE.md)).

Begriffe:

| Stand | Bedeutung |
|---|---|
| **vorhanden** | Im Kern ausgeliefert und automatisch getestet. |
| **teilweise** | Grundfunktion vorhanden, Lücke steht dabei. |
| **experimentell** | Ausgeliefert und getestet, aber Bedienung oder Darstellung können sich noch ändern; im Admin und in `readme.txt` sichtbar gekennzeichnet (Entscheidung E-09). |
| **offen** | Geplant, noch nicht gebaut (mit Fundstelle in `TODO.md`/`ARBEITSLISTE.md`). |
| **nicht geplant** | Bewusst nicht im Umfang. |
| **Pro** | Nur im getrennten Add-on `pass-captain-pro` (siehe [FREE-PREMIUM.md](FREE-PREMIUM.md)). |

Alles, was der Kern ausliefert, bleibt dauerhaft frei (FREE-PREMIUM.md, Regel 1–2).

## 1. Funktionen im Marktvergleich

| Funktion | Kern | Pro | Anmerkung / Fundstelle |
|---|---|---|---|
| Events, Termine, Serien, Ticketarten, Kontingente | vorhanden | – | Serien mit Preisregeln; Duplizieren (A426) |
| Sitzplätze / Saalplan | teilweise, **experimentell** | – | Auswahl vorhanden; mobile Bedienung (Zoom/Pan, Legende mit Form) offen (A090). Entscheidung E-09 (25.11.2026): im ersten Release als „experimentell“ gekennzeichnet (Admin-Seite „Saalpläne“, Modulliste in den Einstellungen, `readme.txt`-FAQ; E09-1) |
| Eigener Checkout (Stripe, PayPal, Mollie, Überweisung, Vor-Ort) | vorhanden | – | GiroCode (A601); Zahlungsarten je Land nur bei Mollie (A154 teilweise) |
| WooCommerce-Checkout inkl. Blocks, HPOS, Multi-Seller | vorhanden | – | `woocommerce*.php`, `tests/e2e/woocommerce-blocks.test.js` |
| Gutscheine, Kampagnen, Codes, Preisregeln (Frühbucher/Nachfrage) | vorhanden | – | Gutscheine über Voucher Captain |
| Vorverkaufs-/Servicegebühr als eigene Position | offen | – | A425 |
| Kombitickets mit gemischten Steuersätzen | offen | – | Runde-2-Rest |
| Rechnungen, Korrekturen, DATEV-Export, Fiskalisierung (AT/DE/HU-Adapter) | vorhanden | – | Adapter laut `fiscalization-adapters.md` |
| Bestellportal, Übertragung, Wiederherstellung, Self-Service-Erstattung | vorhanden | – | A622, R7d |
| Terminumtausch im Portal / Käufer-Teilnehmer-Trennung | teilweise | – | PASS-FE-03/04 |
| Wartelisten (Event und Service-Slots) | vorhanden | – | Double-Opt-in |
| Servicebuchungen / Zeitfenster / Ressourcen | vorhanden | – | Restumfang PASS-FE-17/21 |
| Mitgliedspässe (Laufzeit, Tageslimit, anteilige Erstattung) | vorhanden | – | A524/A526 |
| Online-Scanner, Offline-PWA, Gruppen-Check-in, Geräte-Kopplung | vorhanden | – | Check-out in der PWA offen (Runde-2-Rest) |
| Abendkasse / POS mit Schichten, Z-Bericht, Terminal-Zahlung | vorhanden | – | A139 (Code-Teil) |
| Wallet (Apple/Google) | vorhanden | – | Push im Job-Worker (A374) |
| Ticket-PDF, Druckvorlagen, Etiketten | teilweise | – | Ticketdesign (Logo, Farben, Vorlagen) und Druck-Mapping offen (A685/A686) |
| E-Mail-Vorlagen, Erinnerungen, SMS | vorhanden | – | |
| Berichte, Umsatz nach Zahlungsdatum, CSV je Rolle | vorhanden | – | A613 |
| Veranstalter / Partner-API / Abrechnungen | vorhanden | – | Konditionshistorie (A523) |
| Webhooks, Connectoren (HTTP/JSON, iCal) | vorhanden | – | BoatingSchool/LearnBoating: Pro (Entscheidung 17) |
| Mobile-API (Suche, Batch) | teilweise | – | A687/A688 |
| Form-Captain-Adapter | offen | – | A672 |
| Konfigurierbare Begriffe (Teilnehmende/Gäste) | offen | – | A690 |
| Embed-Widget / Import aus Tickera, Event Tickets, Pretix | offen | – | Runde-2-Rest |
| Multisite (Netzwerkansicht) | teilweise | – | Netzwerkweite Berichte/Cross-Site-Tests offen (A162) |
| Mehrsprachigkeit (5 Sprachen, RTL, Käufer-Sprachwahl) | vorhanden | – | Muttersprachliche Durchsicht extern (A107) |
| Lizenzserver, Premium-Updater | nicht geplant (Kern) | Pro | Kern enthält keinen Lizenzcode (`tests/license-guard.php`) |
| Weitere Pro-Connectoren | – | Pro | Andockpunkte A705/A708 |

## 2. Ende-zu-Ende-Abläufe: automatisiert oder manuell

| Ablauf | Automatisiert geprüft | Manuell bei der Abnahme |
|---|---|---|
| Kauf im eigenen Checkout (Blocks und Classic-Shortcode) | `tests/e2e/checkout.test.js`, `frontend.test.js` (Chromium, Firefox, WebKit in CI-Job `browser`) | Sichtprüfung im Ziel-Theme |
| WooCommerce mit HPOS und Block-Checkout | `tests/e2e/woocommerce-blocks.test.js`, `tests/integration/modules/woocommerce*.php` | Zusammenspiel mit dem Zahlungs-Plugin des Shops |
| Mobilgeräte (Checkout, Ticketseite, Scanner) | E2E mit Mobil-Viewport, `tests/a11y` | Echte Geräte iOS/Android (RELEASE.md) |
| Multisite | `zzzzz_w9_access` (Netzwerkansicht), Präfix-Isolation (`tests/sql-prefix-check.php`) | Netzwerkaktivierung auf echter Multisite |
| Mehrere Scanner gleichzeitig | `zzzzzzz_r4_scanner.php`, `zzzzz_y7_frontend_scanner.php`, `tests/e2e/scanner.test.js` | Gerätetest am Einlass |
| Offline-Konflikte (PWA) | `offline_scanner.php`, `tests/e2e/offline-scanner.test.js`, `tests/js/offline-*.test.js`, CI-Job `pwa` | Flugmodus-Test auf Gerät |
| Druck (PDF, Etiketten, Wallet) | `print.php`, `zzzzzzz_r2_print_wallet.php` | Druck auf Zieldrucker |
| POS / Abendkasse | `boxoffice*.php`, `p3_boxoffice_shifts.php`, `zzzzz_x5_pos_devices.php`, `tests/js/pos.test.js` | Kartenterminal, Bondrucker |
| Erstattungen (alle Kombinationen) | `zzzz_w7_refund_state_delivery.php`, `zzzzzzz_r7d_refunds_selfservice.php`, A132-Module | PSP-Sandbox (A124) |
| Fehlende Drittanbieter (kein Woo, kein Voucher Captain, kein Netz) | `zz_a664.php` (Kern läuft allein), `zz_a378.php` (Gutscheine nur mit Anbieter) | – |
| Update von 1.4.0, Deinstallation, Restore | `tests/upgrade`, `tests/restore`, CI-Job `install-test`, `drills.yml` | Restore auf Kopie der Live-Seite |
| Plugin Check / WordPress.org-Regeln | CI-Job `plugin-check`, `tests/plugin-check` | – |
| Last (REST, Checkout) | `load.yml`, `tests/load` | – |

Offene Gates (A124, A130, A142) und die manuellen Schritte stehen in [RELEASE.md](RELEASE.md).
