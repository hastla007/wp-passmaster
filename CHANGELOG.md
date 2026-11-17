# Änderungsprotokoll

## 1.6.0 – 2026-11-16

Version 1.6.0 ergänzt den Erweiterungsvertrag um die Lesefassade (`WPCAP_CONTRACT_VERSION` `1.1.0` → `1.2.0`, additiv). Sie kam zunächst als Nachtrag in den noch nicht veröffentlichten Stand 1.5.0; weil sie den Vertrag erweitert, trägt sie eine eigene Plugin-Version. Add-ons, die sie brauchen, setzen Pass Captain 1.6.0 beziehungsweise Vertragsversion 1.2.0 voraus. Keine Änderung am Datenbankschema.

- **Neue Mindestversionen (MINVER-1, einheitlich für alle Captain-Plugins): PHP 8.5, WordPress 7.0, WooCommerce 11.2.2** (11.2.0/11.2.1 übernehmen Bestell- und Zahlungsdaten eines früheren Store-API-Checkouts im selben PHP-Prozess, woocommerce#70637). Auf älterem PHP oder WordPress lädt das Plugin nicht und zeigt Administratoren nur einen Hinweis (die Website läuft weiter). Unterstützte Datenbanken: MySQL 9.7+ und MariaDB 12.3+; ältere Server werden nicht gesperrt, „Website-Zustand“ zeigt eine Warnung. Vor dem Update PHP und WordPress beim Hosting prüfen.
- **Lesefassade für Zustände (Vertragsversion 1.2.0, additiv):** `wpcap_get_order_state()`, `wpcap_get_ticket_state()`, `wpcap_get_refund_state()`, `wpcap_get_occurrence_state()` und `wpcap_get_event_state()` (Klasse `PassCaptain\Api\ReadFacade`) liefern den Zustand zu einer Datensatz-ID oder – bei Bestellung und Ticket – zur `public_id`: IDs, Status, Beträge in Kleinsteinheiten mit Währung, Anzahlen und Zeitpunkte in UTC, nie Namen, E-Mail-Adressen, Codes oder Freitext. Nur lesend, ohne Rechteprüfung (der Aufrufer entscheidet über die Anzeige). Beschreibung in `docs/EXTENSIONS.md`, Abschnitt „Lesefassade für Zustände“. Kontakt und Einwilligung zu einem Ticket sind nicht enthalten (offen, F-13).
- **Erinnerungen:** Veraltete Erinnerungen (nach Erstattung, Stornierung, Abmeldung, Terminwechsel oder Absage) werden in der Reihenfolge des Versands aussortiert und in mindestens der Menge, die ein Lauf senden kann. Vorher konnte bei einer per Filter `wpcap_message_batch_size` über 500 angehobenen Stapelgröße eine veraltete Erinnerung ungeprüft hinausgehen.
- **Systemstatus: Kasten „Workflow Captain“ (optional):** Ist Workflow Captain aktiv, zeigt „Pass Captain → Systemstatus“ dessen Status für Pass Captain über `workflow_captain_status_for( 'pass-captain' )`: Adapter-Zustand (angemeldet, abgelehnt mit Grund, nicht angemeldet), Vertragsversion, Zahl aktiver Workflows, Ereignisse je Zustand (je mit Link auf die gefilterte Ereignisliste), letzte Korrelations-ID mit Link auf die Ereignisse von Pass Captain und Links zu Workflows, Läufen, Ereignissen und Anbietern. Nur Zählwerte und Links, keine personenbezogenen Daten; ohne Workflow Captain steht dort ein Satz, Pass Captain bleibt unabhängig davon (kein harter Bezug, keine Klasse von Workflow Captain wird benutzt).
- **Dokumentation:** `docs/MOBILE-API.md` nennt die Routen `/app/offline/*` und führt das ausgelieferte Offline-Paket nicht mehr als geplant; Tests vergleichen `docs/MOBILE-API.md` und `docs/SUITE-EVENTS.md` mit dem Code.

## 1.5.0 – 2026-11-16

Version 1.5.0 ist die erste Version mit den Erweiterungspunkten für Add-ons (Abschnitt „Add-on-Schnittstellen“ weiter unten). Neu ist die Konstante `WPCAP_CONTRACT_VERSION` (Wert `1.1.0`; Version 1.6.0 hebt sie mit der Lesefassade auf `1.2.0`): Sie nennt die Version des Erweiterungsvertrags, die Tabelle „Versionen der Erweiterungspunkte“ in `docs/EXTENSIONS.md` nennt je Hook die Plugin-Version, seit der er besteht. Add-ons, die diese Hooks brauchen, setzen Pass Captain 1.5.0 voraus.

### Wichtige Verhaltensänderungen beim Update
- **Datenbankschema 3.17.7** (über 3.17.0–3.17.6): neue Tabellen `wpcap_checkin_claims`, `wpcap_credit_note_lines`, `wpcap_payment_cases`, `wpcap_mail_suppressions`; neue Spalten u. a. `ticket_types.visibility/access_codes/description/position/attendee_name`, `orders.customer_name/attribution`, `refunds.deducted_minor`, `wallet_passes.synced_at`. Die Aktualisierung läuft automatisch.
- **Bestelltransaktionen laufen mit REPEATABLE READ** (auch wenn der Server READ COMMITTED vorgibt); Deadlocks und Lock-Timeouts im Checkout werden intern bis zu zweimal wiederholt, danach 503 mit `Retry-After`.
- **Ticketmails großer Bestellungen und aller Webhook-Bestellungen** gehen über den Hintergrundjob (sofort fällig) statt im Request; ohne funktionierenden Cron verzögern sie sich.
- **Shortcode/Block `[wp_pass_captain_events]`** zeigt ab mehr als 3 Terminen standardmäßig eine Liste mit Einzelansicht (`layout=auto`); `layout=cards` stellt das alte Verhalten her. Kalenderlinks nutzen `?wpcap_occ=`.
- **Stapeldruck mit Codes** nur noch für Veranstalter-Rollen mit Step-up; standardmäßig nur nie zugestellte Kassentickets.
- **Ticket-PDF** nur bis 50 Tickets bzw. 5 MB als Anhang (Filter `wp_pass_captain_ticket_pdf_limits_v1`), sonst Hinweis auf die Ticketlinks.
- **Steuersatz** richtet sich nach dem Leistungszeitpunkt (Terminbeginn in der Termin-Zeitzone) statt nach dem Bestelldatum.
- **Datenbankschema 3.9.0** (über 3.6.0–3.8.0): neue Tabellen für Service-Warteliste, Preisregeln, Käuferlimits, Einwilligungen, Terminpersonal und Supportzugriffe sowie neue Spalten (u. a. Steuerprofil je Bestellposition, Ratenlimit je API-Key, Zeitzone und Regeln je Ressource). Die Aktualisierung läuft automatisch; schlägt sie fehl, wird sie mit wachsendem Abstand (1, 5, 15, 60 Minuten) wiederholt und kann unter „Pass Captain → Systemstatus“ oder mit `wp pass-captain diagnose --repair` sofort neu gestartet werden.
- **Zugangsdaten werden verschlüsselt gespeichert** (libsodium, Präfix `pcv1:`): Anbieter-Secrets (Stripe, PayPal, Mollie, Twilio, SMS, Wallet) und Webhook-Signing-Secrets werden beim Update migriert. Empfohlen ist eine eigene Konstante `PASS_CAPTAIN_SECRET_KEY` (32 zufällige Bytes, hex) in `wp-config.php`; ohne sie wird der Schlüssel aus `AUTH_KEY`/`SECURE_AUTH_KEY` abgeleitet – werden diese Salts erneuert, müssen alle Zugangsdaten neu eingegeben werden. Secrets werden im Admin nur noch maskiert angezeigt („gespeichert ••••1234“), Webhook-Secrets nur einmal nach dem Anlegen bzw. Erneuern.
- **WooCommerce: negative Gebühren** (z. B. Rabatte als Gebühr) mindern jetzt den Betrag der Pass-Captain-Bestellung – auch bei nur einem Rechnungssteller.
- **Käuferlimits** begrenzen auf Wunsch die Tickets pro Person (E-Mail oder Kundenkonto) über alle Bestellungen – im Online-Checkout und in WooCommerce, nicht an der Kasse. Ohne angelegte Limits ändert sich nichts.
- **Erstattung eingecheckter Tickets** folgt der neuen Einstellung `refund_after_checkin` (Standard „Erlauben“, alternativ „Nur mit Zweitfreigabe“ oder „Nicht erlauben“); sie gilt für manuelle Erstattungen, Terminabsagen und WooCommerce-Erstattungen und hat Vorrang vor der WooCommerce-Option „Eingecheckte Tickets erstatten“.
- **MySQL 8.0 und 8.4 werden unterstützt** (neben MariaDB 10.6–11.4): Ein serverseitiger Rollback in der Bestelltransaktion (Deadlock-Opfer) wird über eine Markierungszeile in einer verbindungslokalen Temporärtabelle erkannt, da MySQL kein `@@in_transaction` kennt. Siehe `docs/COMPATIBILITY.md`.
- **Datenbankschema 3.10.1**: Servicebuchungen erhalten die Spalten `supplement_for`, `origin_order_id` und `origin_minor` (Umbuchung mit Zuzahlung). Die Aktualisierung läuft automatisch.
- **Einwilligungen gelten nur für die Person, die sie gegeben hat**: Wird ein Ticket einer anderen Person zugewiesen oder übertragen, enden die Einwilligungen der bisherigen Person (Nachweis bleibt erhalten); die neue Person willigt selbst ein.
- **Datenbankschema 3.11.0 und Vault-Version 3 (Fiskalisierung)**: neue Tabellen für Rechtsträger, Fiskalprofile, Anbieterkonfiguration, Verkäufer je Ticketart, Fiskalbelege, Belegpositionen, Verkäuferschnappschüsse, Fiskal-Ereignisse und Fiskalstatus je Bestellung; Kassensitzungen erhalten `location_id`/`register_id`. Ohne angelegten Rechtsträger und Ticketzuordnung ändert sich am Verkauf nichts.
- **Fiskalisch zugeordnete Bestellungen geben Tickets erst nach bestätigtem Beleg aus** (Ausfallregel „Verkauf stoppen“): online, in WooCommerce und an der Kasse. An der Kasse bleibt der Verkauf ohne Bonnummer offen, die Kassensitzung kann bis zum „Nachbuchen“ nicht abgeschlossen werden. Ein Datenbankfehler in der Fiskalprüfung sperrt die Ausgabe.
- **WooCommerce mit Fiskalisierung**: Nicht-Ticket-Produkte, positive Gebühren und Versand brauchen in solchen Bestellungen einen Rechtsträger; eine negative Gebühr, die auch auf Nicht-Ticket-Produkte entfällt, wird abgelehnt, solange ein Fiskalbeleg erforderlich ist (ohne Fiskalisierung gilt die bisherige Verteilung).
- **Kassenregister**: Sobald unter „Kassen- und Belegeinstellungen“ Register eingetragen sind, kann eine Kassensitzung nur mit einem freigegebenen Register geöffnet werden; ein entferntes Register sperrt weitere Verkäufe der noch offenen Sitzung.
- **REST `POST /pos/sales`**: optionales Feld `payment_method` (`cash`, `card`, `voucher`, `other`; nur für Fiskalbelege), Antwort zusätzlich `tickets_issued` und `ticket_issuance_pending`.

### Audit Runde 2 (12 Experten, Bericht `docs/audit/PANEL-2026-11-15-R2.md`) und Restpunkte Z1–Z4
- **Concurrency (R1):** kein Überverkauf mehr unter READ COMMITTED; feste Lock-Reihenfolge bei verspäteten Zahlungen, Checkout und Hold-Aufräumen (0 Deadlocks im Lasttest auf MariaDB und MySQL); verspätete Zahlungen prüfen Verkaufszustand und Sitzplätze (`needs_review` statt Überbuchung); PayPal captured nicht nach Hold-Ablauf; abgelaufene Holds werden alle 5 Minuten freigegeben; Last-Harness misst Deadlocks auf MySQL, neue Szenarien `mixed`/`seats`.
- **Druck, PDF, QR, Wallet (R2):** sicherer Stapeldruck (Rechte, Step-up, eine Transaktion bis zur fertigen PDF, Benachrichtigung der Inhaber); komprimierte, gestreamte Ticket-PDFs (500 Tickets: 10,2 → 0,75 MB); Wallet-Sammellink statt eines Anhangs je Ticket, Ausstellung als Job, Skinny-JWT für Google; QR ohne ECI; Transliteration statt „?“; Wallet-Aktualisierung bei Schlüsselwiderruf; gebündelte signierte QR-Werte; Teilnehmername und Käufersprache im PDF; APNs mit Verbindungs-Wiederverwendung und Zertifikatswarnung.
- **E-Mail und Jobs (R3):** werfende Job-Handler legen die Queue nicht mehr lahm; Absender-Einstellungen (From, Name, Reply-To, Return-Path) mit Systemstatus-Warnung; Verschiebungs- und Absagemails über die Queue mit Dedupe je Empfänger; Neuversand ohne Zeilensperren während SMTP, mit PDF und Warnung bei weitergegebenen Tickets; Bounce-/Beschwerde-Webhook, Abmeldelink und `List-Unsubscribe` für Erinnerungen; Retention mit Fortsetzung und Heartbeat; Fehlerereignisse redigiert über `Log`.
- **Check-in und Scanner (R4):** atomare Idempotenz-Claims, Undo mit Zeitfenster/Rollen/Idempotenz, geräteübergreifende Doppel-Einlässe bleiben offen, 503 bei Datenbank-Engpass mit Offline-Fallback, PWA mit Zwischenzustand und Sperre für denselben Code, kompakte Offline-Pakete mit lokalen Einlasszählern, Web-Scanner mit Timeout, Ton und Taschenlampe.
- **Steuer, Rechnungen, Zahlungen (R5):** Steuersatz nach Leistungszeitpunkt; Gutschriften je Steuersatz, Steuerland und Buchhaltungsexport; Widerrufs- und Datenschutzhinweis im Checkout (`wp_pass_captain_checkout_legal_html_v1`); Erstattungshinweis und Portal-Antrag nach Terminverschiebung; Stripe-Teilerstattungen aus dem Dashboard; nachträglich fehlgeschlagene Erstattungen (`wp_pass_captain_refund_failed_v1`); verzögerte Zahlarten bei Mollie/PayPal verlängern den Hold; Klarna/Riverty-Capture; Annullierung offener PSP-Sessions, Zahlungsfälle und Status-Poll.
- **Frontend (R6):** Shortcode-/Block-Attribute `event`, `occurrence`, `limit`, `layout`; Live-Verfügbarkeit (`GET /availability`, Countdown zum Verkaufsstart, Action `wp_pass_captain_availability_changed_v1`); Warteliste im Frontend; Käuferlimit pro Konto im nativen Checkout; „Erneut bezahlen“/„Reservierung freigeben“ nach Rückkehr vom Zahlungsanbieter; Pflichtname, neue Feldtypen (Auswahl, Radio, Datum, Telefon), Käufername; Beschreibung und Reihenfolge von Ticketarten, „kostenlos“; Client-Retry mit `Retry-After`; mobiler Saalplan mit Zoom und „Beste Plätze“; Mengen-Stepper.
- **Admin und Produkt (R7):** Seriengenerator kopiert Ticketarten vollständig; Freikarten/Gästeliste ohne Kassensitzung; Event-Detailseiten mit schema.org/Event; Mengenrabatt, automatische Rabatte, Limit pro Käufer, Einzelcodes in Serie; versteckte Ticketarten mit Freischaltcodes; Bestellsuche nach Namen, Bestellungs-CSV; Erstattungsvorschau mit Gebühr und Höchstbetrag; Startseite mit Kennzahlen und Einrichtungs-Checkliste, gruppiertes Menü, Duplizieren; Self-Service-Storno im Portal; datensparsames Tracking (`wpcap:track`, UTM); CSV-Import von Events.
- **Missbrauchsschutz und Architektur (R8):** Checkout-Ratenlimit mit Proxy-Erkennung, IPv6-/64 und E-Mail-Aliasen, fehlgeschlagene Versuche zählen nicht; Hook-Vertragstest mit Signaturen; Downgrade-Erkennung; Multisite-CI und robuste Netzwerk-Deinstallation; Portal-Zugang als `Orders\PortalAccess`.
- **Restpunkte Z1–Z4:** Double-Opt-in für Wartelisten, Zugangstokens per Header/Cookie statt URL, WooCommerce-Produktlinks nur für eigene Events, atomare Ratenlimits in eigener Tabelle, Modul-Manifest, strikte CSP ohne Inline-Handler, SMS-Opt-in nur mit bestätigter Nummer, Stripe-Erstattungs-Retry nach endgültiger Ablehnung, Terminal-Betragsabgleich, Kassenbestand mit Teilerstattungen, Abrechnung nach rabattiertem Wert.
- **Bewusst offen:** Kombitickets mit gemischten Steuersätzen, Zusatzprodukte/Pakete, Pay-what-you-want/Resale/Abos, eingebettete Unicode-Schrift im PDF, Check-out in der PWA, Embed-Widget, Import fremder Ticketsysteme, Terminumtausch im Portal (siehe TODO.md).

### Audit-Panel 2026-11-15 (8 Prüfer, Bericht `docs/audit/PANEL-2026-11-15.md`)

**Verhaltensänderungen beim Update**
- **Datenbankschema 3.14.0** (über 3.12.0/3.13.0): Spalte `request_fingerprint` an Bestellungen, Indizes `status_expires` (Reservierungen) und `created_checkin` (Check-ins). Upgrade-Schritte: Lösch-Opt-in wandert in die eigene Option `wpcap_uninstall_policy` (die alte Kopie „WP Pass Captain“ kann so keine Daten mehr löschen), `wpcap_settings` wird automatisch geladen (autoload).
- **REST `POST /checkout`**: Antwort ohne `order_id`/`public_id` (nur `reference`). Ein wiederverwendeter Idempotency-Key mit anderen Angaben (Zahlungsart, Positionen, E-Mail, Codes) liefert `409 wpcap_idempotency_mismatch`; nach Ablauf der Reservierung `409 wpcap_checkout_closed`. Offene Stripe-/Mollie-Zahlungen werden nur bei gleichem Betrag wiederverwendet.
- **Strenge Datumsprüfung** in den REST-Endpunkten für Kampagnen (`starts_at`/`ends_at`), Fiskalprofile (`effective_from`/`effective_until`) und Service-Slots: `Y-m-d H:i:s` (UTC) oder ISO 8601 mit Offset, sonst `400 wpcap_invalid_datetime`.
- **E-Mail-Adressen über 190 Zeichen** werden mit klarer Feldmeldung abgelehnt (Käufer, Teilnehmer, Ticket-Weitergabe).
- **Gestapelte Rabattcodes**: Jeder Code wird anteilig auf die Zeilen verteilt; bei überlappenden Codes kann der Gesamtrabatt kleiner ausfallen als bisher, entspricht aber genau den Zeilenrabatten auf Rechnung, Fiskalbeleg und Abrechnung (`docs/SALES-MODELS.md`).
- **Reservierungsdauer**: Stripe-Checkouts halten mindestens 36 Minuten (Session endet 5 Minuten vorher); gestartete verzögerte Zahlarten (SEPA, Banküberweisung) verlängern die Reservierung auf 7 Tage (Filter `wp_pass_captain_delayed_payment_hold_minutes_v1`).
- **Neue Löschfristen** (Einstellungen unter „Datenschutz“): Käuferdaten aller Bestellungen nach der gesetzlichen Frist (Standard 10 Jahre ab Ende des Bestelljahres; Rechnungen und Fiskalbelege bleiben unverändert), Wartelisten (30 Tage nach dem Termin), Mitgliedspässe (365 Tage nach Ablauf), stornierte/abgelaufene Servicebuchungen (90 Tage), SMS-Texte (30 Tage), Einlassbuchungen/-konflikte und Gerätebezeichnungen (365 Tage), erledigte Reservierungen und Hintergrundjobs (30 Tage). Der erste Lauf auf Bestandssystemen arbeitet ältere Daten in Batches ab.
- **Beim Löschen alle Daten entfernen** lässt sich nur noch mit der Eingabe „LÖSCHEN“ (oder „DELETE“) aktivieren.
- **Affiliate-Cookie** gilt ohne WP Consent API als nicht eingewilligt.
- **QR-Codes** werden ohne `endroid/qr-code` direkt aus BaconQrCode gezeichnet (keine Kollision mehr mit Plugins, die eine ältere endroid-Version mitbringen); die Statusseite warnt, wenn ein anderes Plugin BaconQrCode lädt.
- **Offline-Scanner**: Ein gesperrtes Gerät (401/`device_revoked`) löscht Zugangsdaten, Pakete und Protokoll – noch nicht synchronisierte Scans gehen dabei verloren; abgelaufene Pakete und alte Protokolleinträge werden automatisch entfernt.
- **Terminabsage** widerruft zuerst alle Tickets in einem Schritt; Erstattungen, Mails und Wallet-/Webhook-Effekte laufen als Hintergrundjobs (Fortschritt im Systemstatus).

**Sicherheit**
- REST `POST /member-passes` prüft den Geltungsbereich jeder Regel wie die Admin-Maske (keine Service-Pässe für fremde Ressourcen mehr durch Kassenpersonal); Scope-Matrix-Test für alle schreibenden Routen mit Objekt-IDs.
- CSV-Exporte ohne Backslash-Escape und mit erweiterter Formel-Neutralisierung (CSV-/Formel-Injection).
- Entwickler-API-Keys gelten nur, solange Rolle und Event-Zuweisung bestehen; Widerruf bei Löschen/Rollenwechsel. Nicht-Admins höchstens 6.000 Anfragen/Minute.
- Token-Seiten (Portal, Ticket, Rechnung, Warteliste, Einwilligung, Service-Portal, Scanner) senden `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `X-Robots-Tag: noindex` und `DONOTCACHEPAGE`.
- Wartelisten: globales IP-Limit (Filter `wp_pass_captain_waitlist_ip_limit_v1`), IPv6-Limits je /64; Check-in-Wiederholungen prüfen die Berechtigung erneut; Array-Parameter in URLs erzeugen keine PHP-Warnungen mehr.

**Zahlungen und Belege**
- Erstattungen und Chargebacks im Dashboard von Stripe, PayPal und Mollie werden im Erstattungsbuch gebucht (Gutschrift, Abrechnungsabzug); Disputes lösen eine Admin-Mail aus.
- Verspätete Zahlung nach Ablauf der Reservierung belegt den Rabattcode neu, sonst manuelle Prüfung (`needs_review`).
- Abrechnung und Finanzbericht ziehen bei Erstattungen auch den Gutschein-Anteil ab; Einzweck-Gutscheine werden auf die Zeilen verteilt (Rechnung, Fiskalbeleg, Abrechnung stimmen mit der Summe überein); Teilerstattungen bei Kampagne plus Gutschein korrekt skaliert; Umbuchung auf günstiger nach Zuzahlung erstattet über beide Bestellungen; Gutschriften für Erstattungen vor der Rechnungsstellung; PayPal bucht nicht mehr für bereits geschlossene Bestellungen ab.
- Signierte QR-Codes bleiben nach einer Terminverschiebung gültig.

**Datenschutz**
- Auskunft/Löschung eines Käufers erfasst nur noch eigene Einwilligungen; nie bezahlte Bestellungen verlieren auch die Rechnungsanschrift; Wallet-Pässe werden bei Löschung ohne Namen aktualisiert; Wartelisten-E-Mails werden für die Support-Rolle maskiert; WooCommerce-Teilnehmerkopien werden gelöscht und sind in den WooCommerce-Datenschutzwerkzeugen; Rate-Limit-Schlüssel mit geheimem Schlüssel (HMAC); `Redact` maskiert Telefonnummern, IBAN und IP-Adressen; Export/Löschung um Benutzerkonto, Erstattungsgrund, Nachrichtenwarteschlange, SMS und Ansprechpartner ergänzt; Datenschutztext an die tatsächlichen Fristen und Empfänger angepasst.

**Performance**
- Event-Seite, Block und `GET /events` laden gebündelt (konstant rund 15–20 Abfragen statt mehrere je Ticketart); Mollie-Zahlarten pro Währung/Land gecacht mit Negativ-Cache und 3 s Timeout; Erinnerungen ohne Abfrage je Inhaber und ohne 200-Termine-Grenze; Admin-Badges als COUNT; Berichtsseite gecacht, Zeitraum höchstens 366 Tage; Audit- und Fiskal-Export streamen ohne 50.000-Zeilen-Grenze; WooCommerce-Block-CSS nur noch im Checkout.

**WooCommerce, Multisite, WordPress.org**
- Kopierte `_wpcap_*`-Meta (z. B. Abo-Verlängerungen) kann keine fremden Tickets mehr stornieren; Papierkorb/Löschen unbezahlter Bestellungen gibt Reservierungen frei; Admin-/REST-Bestellungen mit Pflichtfeldern bekommen Tickets, die Angaben folgen im Portal; Audit-Einträge auf Sites ohne Plugin-Tabellen werden übersprungen; Netzwerkaktivierung in Batches; eigene PHP-Versionsprüfung statt Composer-`platform_check`; `readme.txt` „External services“ vervollständigt, tweetnacl-Quellhinweis im Paket.
- Neu: `Campaigns::create`/`Fiscalization::save_profile` liefern die richtige ID; Monatskalender gruppiert in der Zeitzone des Termins; Deinstallation entfernt Action-Scheduler-Aktionen.

### Fiskalisierung (PR #14 integriert)
- Modul **Fiskalisierung** (Pass Captain → Fiskalisierung): Rechtsträger, Länder-/Kanalprofile mit Prüfvermerk (Regelwerk, Version, Referenz), Anbieter je Rechtsträger, Verkäufer je Ticketart, Verbindungstest, offene Vorgänge mit Statusabgleich und Wiederholung, Ereignisprotokoll mit „Extern erledigt dokumentieren“, einmaliges efsta-Vollstorno (AT/DE, nur vor Zahlung), CSV-Archivexport (kein Behördenformat). Adapter: fiskaly SIGN AT, SIGN DE, Unified API (FR, IT, PT, SE), efsta EFR, fiskaltrust POS System API. Kein zertifiziertes Produkt; Anbieter-Sandbox-Abnahme und rechtliche Prüfung bleiben beim Betreiber (`docs/fiscalization-adapters.md`, `docs/fiscalization-scope-at-de-hu.md`).
- **Ein gehärteter Transport** (`Fiscalization\ProviderHttp`) für alle Adapter: nur HTTPS zu öffentlichen Adressen (SSRF-Schutz), lokale Fiskaldienste nur nach Freigabe, keine Weiterleitungen, Zeit- und Größenlimits, Beträge mit den Nachkommastellen der Währung, Fehler über `PassCaptain\Log` (nur Host und Code). Endpunkte werden schon beim Speichern geprüft. Der frühere `Providers\HttpClient` entfällt.
- **Zugangsdaten im Vault** (Kontext je Rechtsträger und Anbieter, Schlüsselwechsel, Migration des Entwurfsformats); nie per REST, nie im Audit-Log; Anbieter-Token nur verschlüsselt zwischengespeichert.
- **Verkäufermodell**: Rechnungssteller (Verkaufsmodell) bestimmt die Bestellung, der fiskalische Rechtsträger muss dazu passen; Belege je Bestellung und Rechtsträger, bei WooCommerce-Aufteilung je Teilbestellung. Vorrangregeln in `docs/WOOCOMMERCE.md`.
- Korrekturaufgaben bei Erstattung, Stornierung und Event-Absage (`manual_required`) an den bestätigten Belegen, auch für erstattete WooCommerce-Waren, -Gebühren und -Versand über alle Teilbestellungen.
- Automatische Wiederholung mit gespeichertem Abstand (`next_attempt_at`), Statusabfrage vor jeder Wiederholung, gleicher Idempotenzschlüssel; endgültige Fehler mit Admin-Mail und Log.
- Behoben gegenüber dem Entwurf: Belegpositionen wurden mit einer nicht vorhandenen Spalte gespeichert, Admin-Formulare nutzten eine nicht existierende REST-Methode, Statusabfragen von efsta/fiskaltrust/fiskaly lieferten keinen Status, Kampagnenrabatte fehlten im Belegbetrag, Erstattungen mit mehreren Belegen wurden doppelt als „nicht zugeordnet“ markiert.

### Add-on-Schnittstellen (für „Pass Captain Pro“, keine Premiumfunktionen im Kern)
- **Admin-Menü:** Action `wp_pass_captain_admin_menu_built_v1( $parent_slug )` am Ende des Kernmenüs – Add-on-Seiten ohne Modulklasse und unabhängig von der Hook-Reihenfolge.
- **Rechnungen:** Action `wp_pass_captain_invoice_created_v1( $id, $type, $context )` nach jeder Rechnung, Rechnungskorrektur und abgeschlossenen Veranstalter-Abrechnung; versionierte Lese-API `PassCaptain\Api\Invoices::get( $id, $type )` bzw. `wpcap_get_invoice()` (Nummer, Datum, Art, Rechnungssteller und Käufer mit Anschrift und USt-IdNr., Währung mit Nachkommastellen, Positionen mit Netto/Steuersatz/USt/Brutto, Summen, Referenzen); Filter `wp_pass_captain_invoice_mail_attachments_v1` (Anhänge an Ticket-Mail bzw. Erstattungsbestätigung) und `wp_pass_captain_invoice_links_v1` (Links/Downloads in Admin, Rechnungsansicht, Bestellportal, Abrechnung).
- **White Label:** Filter `wp_pass_captain_brand_name_v1`, `wp_pass_captain_brand_logo_v1`, `wp_pass_captain_admin_menu_title_v1`, `wp_pass_captain_admin_menu_icon_v1` – Admin-Menü, übersetzte Texte mit dem Produktnamen, Offline-Scanner (Titel, Logo, neues öffentliches Manifest `GET /app/offline/manifest`), Web-Scanner, Bestellportal, Ticketseite, PDF-Metadaten. Service-Worker-Cache des Offline-Scanners auf `v5`.
- **Zugangsschranke vor dem Kauf:** Filter `wp_pass_captain_checkout_allowed_v1( true, $context )` (z. B. virtueller Warteraum) im nativen Checkout, bei Kunden-Servicebuchungen und in WooCommerce (Warenkorb, Checkout, Store API); nicht für Kasse, Admin und interne Quellen. Ablehnungen erreichen den Käufer mit Status und `Retry-After`; Fehler im Add-on blockieren keinen Verkauf.
- **API-Scopes:** Filter `wp_pass_captain_api_scopes_v1` für weitere Scopes der Entwickler-API-Keys (erscheinen auf der Seite „Entwickler-API-Keys“) und `PassCaptain\Api\Auth::request_can( $request, $scope[, $event_id] )` bzw. `wpcap_api_request_can()` mit derselben Prüfung wie die Kernrouten (Key, Scope, Event, Ablauf/Widerruf, Ratenlimit) und genauen Fehlercodes (401/403/429).
- **Kartenterminals an der Kasse (asynchron):** Vertrag `PassCaptain\BoxOffice\DeviceGatewayInterface` (Version 1: `label()`, `start( $sale )`, `poll( $reference )`, `cancel( $reference )`), registriert über den Filter `wp_pass_captain_pos_payment_devices_v1` (Instanz oder Callback-Array, optional auf Register beschränkt), Zeitlimit `wp_pass_captain_pos_device_timeout_v1` (Standard 180 s), Actions `wp_pass_captain_pos_device_payment_started_v1`/`_completed_v1`/`_failed_v1`. Die Zahlart „Karte am Terminal (automatisch)“ (`card_device`) erscheint nur mit registriertem Gerät: Der Verkauf bleibt mit Reservierung offen, die Kasse zeigt „Am Terminal bezahlen …“ (Live-Region, Abbrechen) und fragt den Status über `admin-ajax` (`wpcap_pos_device_status`/`wpcap_pos_device_cancel`, Nonce je Verkauf, nur Kassierer oder Administrator) ab; nach Bestätigung wird wie bei Bar/Karte gebucht (Fiskalbeleg vor Tickets), Fehler, Abbruch und Zeitüberschreitung geben das Kontingent frei. Webhook-Bestätigung durch das Add-on: `Service::confirm_device_payment( $sale_key_or_order_id, $provider_reference )` bzw. `wpcap_confirm_pos_payment( $order_id, $reference )` – idempotent, auditiert, nie doppelt gebucht. Kassen-Migration 3 ergänzt die Verkaufstabelle um `device_*`-Spalten. Der Kern enthält keinen Terminal-Anbieter.
- Ohne Callbacks verhält sich der Kern unverändert. Beschreibung: `docs/EXTENSIONS.md`, Abschnitt „Pass Captain Pro / Add-on-Schnittstellen“.

### Restpunkte: Umbuchung mit Zuzahlung, Kasse, Einlass, Berichte, Betrieb
- **Servicebuchungen: Umbuchung auf ein teureres Zeitfenster** (Preisregel `refund_difference`): Statt der Ablehnung entsteht eine verknüpfte Zuzahlungsbestellung über die Preisdifferenz (normaler Bestell- und Zahlungsweg, Weiterleitung zum Zahlungsanbieter aus dem Portal). Der neue Platz ist bis zum Ablauf der Zahlungsfrist reserviert, die bisherige Buchung bleibt bis zur Zahlung bestehen; nach Zahlungseingang wird atomar umgebucht (Tickets der Zielressource ersetzen die alten). Unbezahlte Zuzahlungen geben nur den reservierten Platz frei; eine verspätete Zahlung bucht um, solange der Platz frei ist, sonst wird sie erstattet. Eine spätere Stornierung erstattet beide Zahlungen.
- **Kasse:** Teilnehmerfelder vom Typ „Einwilligung“ erscheinen als Checkbox mit Einwilligungstext und Version und werden wie im Checkout protokolliert (Quelle `box_office`); die Erklärung einer aktiven Preisregel steht neben der Ticketart.
- **Tickets-Liste für Terminpersonal:** Wer nur einzelnen Terminen zugewiesen ist, sieht in „Tickets“ genau die Tickets dieser Termine (inkl. Check-in zurücknehmen).
- **Check-in zurücknehmen nach Wiedereinlass:** Zurückgenommen wird nur der letzte Einlass; der Ticketstatus und „anwesend“ ergeben sich aus den verbleibenden Einlässen (ein Einzelticket bleibt nach Rücknahme des Wiedereinlasses eingecheckt). Gilt auch für Mehrfachtickets.
- **Annahmefrist der Event-Warteliste** ist jetzt die Einstellung „Event-Warteliste: Annahmefrist eines Angebots (Stunden)“ (Standard 24, 1–720); der Filter `wpcap_waitlist_offer_hours` überschreibt sie weiterhin.
- **Einwilligung bei Inhaberwechsel:** Die neue Person erhält in der Ticket-Mail einen Link zur eigenen Einwilligung (`?wpcap_consent=`, Ticketcode als Berechtigung); hält die Käuferin das Ticket selbst, kann sie im Bestellportal einwilligen.
- **Kampagnenbericht:** Erstattungen werden der Kampagne ihrer Bestellung zugeordnet (Spalten „Erstattet“ und „Umsatz nach Erstattung“, auch im CSV); Bestellungen mit zwei Codes werden im Verhältnis der Rabatte aufgeteilt statt doppelt gezählt.
- **REST `POST /tax-profiles`:** unterstützt Bezeichnung, Hinweis zum Leistungsort, Aktualisieren eines Entwurfs (`tax_profile_id`) und `activate` – mit denselben Prüfungen wie die Admin-Seite.
- **Systemstatus:** „Alle erneut versuchen“ je Warteschlange (höchstens 100 Einträge pro Klick, Filter `wpcap_ops_bulk_retry_cap`, mit Nonce, Berechtigung und Audit-Log) und ein Dashboard-Widget mit den kritischen Problemen für Administratoren.

- **Auszahlungsdaten der Veranstalter werden verschlüsselt gespeichert** (Schema 3.10.0, Vault-Version 2): IBAN/BIC/Kontoinhaber in `wpcap_partners` und in den Schnappschüssen abgeschlossener Abrechnungen werden beim Update einmalig verschlüsselt (an Veranstalter bzw. Abrechnung gebunden). Im Admin erscheinen sie nur noch gekürzt („•••• 3000“); ein leeres Feld behält den gespeicherten Wert. Vollständig nur im CSV-Export der Abrechnung für Finanzen/Admins (Zeile „Auszahlungskonto“), nie im Druckbeleg oder in der Partner-API.
- **Erstattungs-Webhook für eine nie bezahlte Bestellung** (z. B. vor dem `paid`-Webhook oder bei abgelaufener Reservierung): Die Bestellung wird storniert (Reservierungen freigegeben, Audit `provider_refund_unpaid_cancelled`) statt ohne Erstattungsbeleg als „erstattet“ markiert. Ein später eintreffender `paid`-Webhook stellt keine Tickets aus und alarmiert den Admin. Teil-Erstattungs-Ereignisse bleiben reine Protokolleinträge.
- **Stripe mit Währungen mit 3 Nachkommastellen** (BHD, JOD, KWD, OMR, TND): Beträge, die keine Vielfachen von 0,010 sind, lehnt Pass Captain vor dem Aufruf ab (Stripe würde sonst runden und der Webhook-Betrag passte nicht mehr zur Bestellung) – Preise in diesen Währungen auf 0 enden lassen.

### Zahlungen und Erstattungen: Wiederholung und Randfälle
- WooCommerce: Scheitert die Übernahme einer WooCommerce-Erstattung (Fehler oder Exception), plant Pass Captain genau einen Wiederholungsjob je WooCommerce-Erstattung; er läuft mit Backoff weiter, bis die Erstattung gebucht ist, und erscheint bei dauerhaftem Fehler mit der Fehlermeldung als Dead-Letter auf der Systemstatus-Seite (dort erneut startbar). Bisher wurde nur bei Datenbankfehlern wiederholt, sonst nur eine Bestellnotiz geschrieben.
- WP Affiliate Captain: Scheitert das Melden eines bezahlten Verkaufs, wiederholt der Job `affiliate_sale_retry` (einer je Bestellung) die Meldung; Zahlung und Tickets bleiben unberührt.
- Job-Queue: Handler dürfen statt `false` einen `WP_Error` zurückgeben; dessen Meldung erscheint als letzter Fehler auf der Systemstatus-Seite.
- Käuferlimits gelten auch für verspätete Zahlungen abgelaufener Reservierungen: Würde die Person ein Limit überschreiten, werden keine Tickets ausgestellt; die Bestellung geht auf „Prüfung nötig“ (Admin-Mail „Bezahlte Bestellung über dem Käuferlimit“).
- Währungen mit 0 und 3 Nachkommastellen in Berichts-CSV, Buchhaltungs-CSV, Veranstalter-Abrechnungs-CSV, Netzwerk-CSV und in den Beträgen an Fiskal-Dienste (fiskaltrust, efsta): z. B. `1500` (JPY) und `12.345` (KWD) statt `15.00` bzw. `123.45`.

### Signierte QR-Codes und konkrete Connectoren
- **Signierte QR-Codes (optional, Standard aus):** Mit „Signierte QR-Codes ausgeben“ enthalten neue QR-Codes (Ticketseite, PDF, Druckvorlagen, Wallet) statt des Ticketcodes `PC1.<Claims>.<Ed25519-Signatur>` mit Ticket-ID, Termin, Gültigkeitszeitraum, Schlüssel-ID und Code-Version – ohne personenbezogene Daten. Web-Scanner, Offline-PWA, App-API, Offline-Sync und der Check-in-Dienst nehmen beide Formate an; der Server prüft weiterhin den Ticketstatus, eine Neuausstellung macht alte QR-Codes ungültig. Die Offline-PWA prüft Signatur, Zeitraum und Termin lokal. Signaturschlüssel im Vault, Rotation mit Übergangsfrist oder sofortiger Sperre auf der Seite „Offline-Scanner“. Siehe `docs/SECURITY.md`, `docs/OFFLINE-SCANNER.md`.
- **HTTP/JSON-Connector** (Seite „Konnektoren“): gewählte Ereignisse als JSON-POST an HTTPS-Ziele (CRM, Zapier, Make, n8n) über die Job-Queue mit Wiederholungen, verschlüsseltem Auth-Header, Feldzuordnung (Bestell-, Ticket-, Teilnehmer-, Termin-Felder; personenbezogene Felder nur mit Opt-in), Idempotency-Key, Test-Versand und Zustellprotokoll mit Wiederholstatus und „Jetzt wiederholen“.
- **Kalender-Abos:** geheime iCalendar-URLs je Event, Veranstalter oder für alle Events; verschobene und abgesagte Termine werden über `SEQUENCE`/`STATUS:CANCELLED` aktualisiert, URLs lassen sich erneuern und widerrufen.
- Neue Tabellen des Moduls `Connectors` (`wpcap_connector_endpoints`, `wpcap_connector_deliveries`, `wpcap_calendar_feeds`, `wpcap_calendar_sequences`) werden automatisch angelegt (Modulschema).

### Robustheit bei Datenbankfehlern, Zustandsautomaten und Randfälle
- Datenbankfehler (Deadlock, Lock-Timeout, Verbindungsabbruch, fehlgeschlagener Commit) führen in keinem kritischen Ablauf mehr zu einer Erfolgsantwort ohne gespeicherten Zustand oder zu halben Buchungen: Zahlungs-Webhooks antworten bei fehlgeschlagener Bestellsuche bzw. noch laufender Verarbeitung mit 5xx statt „ignored“/„duplicate“; Zahlungsbestätigung ohne Transaktion kann keine bezahlte Bestellung ohne Tickets mehr hinterlassen; fehlende Tickets bezahlter Bestellungen werden per Job-Queue nachgeholt; Check-in behandelt Lesefehler nicht mehr als „unbekanntes Ticket“, „keine Regel“ oder „keine Einlässe“; Offline-Scans mit vorübergehendem Serverfehler bleiben in der Scanner-App und werden erneut hochgeladen.
- Kasse: Ein Lesefehler storniert keinen bezahlten Verkauf mehr; hängende Verkäufe werden beim erneuten Absenden fortgesetzt. Beleg-/Rechnungsnummern werden nie doppelt vergeben (blockierte vorher alle weiteren Kassenverkäufe).
- Erstattungen: Lesefehler erzeugen keine 0,00-Erstattung, keinen „fehlgeschlagen“-Status und keine Verbuchung ohne Auszahlung (kein Rückfall auf „manuell“); Teil-Erstattungen rabattierter Bestellungen buchen die richtige Umsatzsteuer.
- Warteschlangen (Jobs, E-Mails, Webhooks, SMS): Einträge, die den Worker bei jedem Versuch abstürzen lassen, landen nach den erlaubten Versuchen im Fehler-/Dead-Letter-Status statt endlos wiederholt zu werden; Webhook- und SMS-Versand erst nach dauerhaftem Claim (keine Doppelzustellung).
- Rückgängig gemachter Check-in macht erstattete oder stornierte Tickets nicht mehr wieder gültig.
- Rabatte werden exakt und nur auf die Positionen verteilt, für die der Code gilt (Steuerbasis bei gemischten Steuersätzen); Rechnungen, Erstattungen, Berichte und Abrechnungen nutzen dieselbe Aufteilung.
- Währungen mit 0 und 3 Nachkommastellen (JPY, KWD, BHD …) werden im Admin, im Checkout, in Mails, Belegen, Rechnungen und Berichten richtig eingegeben und angezeigt.
- Verkaufsbeginn „N Tage vorher“ und aus der Vorlage übernommene Verkaufsfenster einer Terminserie beachten die Sommerzeit (lokale Uhrzeit bleibt gleich).
- Multisite: Serien-Preisregeln und Absage-Zusammenfassungen werden je Site gecacht.
- Der Event-Bericht (REST) wird bei Zahlung, Check-in, Erstattung, Storno und Eventänderungen sofort aktualisiert statt bis zu 5 Minuten veraltet zu sein.
- Gutschein-Anbieter: Zeitüberschreitungen und Antworten außerhalb des Vertrags werden sauber als Fehler behandelt; eine Bestellung wird nie ohne reservierten Gutschein reduziert.
- Seating: Eine fehlgeschlagene Saalplanabfrage legt keine Bestellung ohne Sitzplätze mehr an.
- Neue Tests (`docs/TESTING.md`, `docs/STATE-MACHINES.md`): Fault-Injection-Matrix, Zustandsautomaten, Worker-Neustarts, Upgrades von 1.0/1.2/1.3, Präfix-Isolation, Sommerzeit, Geld-Eigenschaftstests, Berichts-Cache, Vertragskompatibilität, Datenschutz mit großen Datenmengen; erweiterter Lauf `tests/integration/run-slow.sh`.

### Systemstatus und Diagnose
- Neue Seite „Pass Captain → Systemstatus“ (Recht `manage_options`): Rückstände und Fehler der Warteschlangen (Jobs, E-Mails, Webhooks, SMS), überfällige und hängende Einträge, E-Mail-Versandfehler, WP-Cron-Zustand je Aufgabe (inkl. `DISABLE_WP_CRON` ohne externen Cron) mit Worker-Heartbeats; fehlgeschlagene Einträge lassen sich protokolliert wiederholen oder verwerfen. Bei kritischem Rückstand erscheint ein Admin-Hinweis und eine Zahl am Menüpunkt.
- Diagnose und Abgleich (nur lesend, Reparatur nur wo gefahrlos wiederholbar und protokolliert): abgelaufene offene Reservierungen, bezahlte Bestellungen ohne Tickets, gültige Tickets ohne gültige Bestellung, doppelte Erstattungen, hängende Queue-Claims, ausgezahlte aber nicht verbuchte Erstattungen, Erstattungen ohne Anbieter-Referenz (manuell als erledigt/fehlgeschlagen markierbar) und Schemafehler.
- WP-CLI: `wp pass-captain status` und `wp pass-captain diagnose [--repair|--repair-all] [--strict]` für Monitoring; Hinweise zum Alarmieren in `docs/OPERATIONS.md`.

### Neue Admin-Seiten
- „Entwickler-API-Keys“: lesende Keys je Event mit Berechtigungen, Ablauf und eigenem Ratenlimit (Standard 600 Anfragen/Minute); der Key wird genau einmal angezeigt, gespeichert wird nur ein Hash.
- „Ressourcen & Zeitfenster“, „Wartelisten“ (Angebot an die nächste Person, Entfernen, protokollierter CSV-Export), „Kampagnen“ (Rabattcodes anlegen, bearbeiten, (de)aktivieren) und „Mitgliedspässe“ (ausstellen, Gültigkeit und Passregeln bearbeiten, widerrufen) – jeweils mit Nonce-, Rechte- und Eventprüfung sowie Audit-Einträgen.

### Servicebuchungen und Wartelisten
- Buchungsregeln je Ressource: Buchungsschluss, Vorlauf, Höchstplätze pro Person und Zeitraum, Puffer vor/nach Zeitfenstern (keine Überschneidungen derselben Ressource), Standarddauer, Wochengenerator, eigene Zeitzone und Veranstalter-Zuordnung (Partner sehen nur ihre Ressourcen).
- Umbuchung und Stornierung durch Kund:innen mit Fristen und automatischer Erstattung; Umbuchung bei Preisunterschied wahlweise nur zum gleichen Preis oder mit Erstattung der Differenz. Optionale manuelle Bestätigung durch den Anbieter mit Frist (Ablehnung oder Fristablauf = automatische Erstattung).
- No-Show-Regel (Markieren, Anspruch verfällt, Gebühr vermerken) mit Karenzzeit und optionaler Buchungssperre nach wiederholten No-Shows.
- Warteliste für ausgebuchte Zeitfenster mit befristeten Angeboten, die die Plätze bis zum Ablauf reservieren.
- Event-Warteliste mit Automatik: Wird Kapazität frei, erhält die nächste Person automatisch ein Angebot mit Reservierung (24 Stunden, Filter `wpcap_waitlist_offer_hours`) samt Ablehnungs- und Austragelink; die Annahme übernimmt die Reservierung in derselben Transaktion (keine falschen „ausverkauft“-Anzeigen bei parallelen Annahmen).
- Service-Pässe: Passregeln mit Geltungsbereich (Eventeinlass, Service-Ressourcen oder beides), terminfrei oder terminpflichtig, Ablauf je Regel.
- Neue Mailvorlagen (Bestätigung, wartet auf Bestätigung, Anfrage an den Anbieter, abgelehnt, storniert, umgebucht, nicht erschienen, Warteliste Eintragung/Platzangebot), REST-Endpunkte und öffentliche Buchungs-/Wartelistenseiten. Dokumentation: `docs/SERVICE-BOOKINGS.md`.

### Sicherheit
- Ausgehende Verbindungen zu konfigurierbaren Zielen (Webhooks, generischer SMS-Anbieter, Fiskaldienste) nur noch per HTTPS an öffentliche Adressen: private, Link-local- und Metadaten-Adressen werden nach DNS-Auflösung blockiert, Weiterleitungen nicht verfolgt, Zeit und Antwortgröße begrenzt. Ein lokaler Fiskaldienst (z. B. efsta EFR) kann ausdrücklich freigegeben werden.
- Vertrauenswürdige Proxys/CDN für Rate-Limits: Client-Adressen aus Headern werden nur noch von konfigurierten Proxys übernommen (Einstellung, Konstante `WPCAP_TRUSTED_PROXIES`, Filter `wpcap_trusted_proxies`).
- Zentrale Schwärzung von Secrets, Codes und E-Mail-Adressen in Audit-Details, Fehlerprotokollen und Anbieterfehlern; strengere Prüfung von Mengen und Tokens.
- Tests für die Berechtigungsmatrix aller REST-Routen und Admin-Aktionen, Schwärzung, SSRF und Proxys. Dokumentation: `docs/SECURITY.md`.

### Betrieb, Konnektoren und Erweiterungen
- Zentraler Logger (`wpcap_log`, Audit-Tabelle, PHP-Fehlerprotokoll nur mit `WP_DEBUG_LOG` oder Filter `wpcap_log_to_error_log`) und private Temporärdateien statt direkter Dateisystemaufrufe.
- Fehler beim Markieren erstatteter Bestellungen und bei extern ausgeführten Erstattungen werden erkannt, vollständig zurückgerollt und automatisch wiederholt; ein Zustellmarker verhindert eine zweite Ticket-Mail mit neuen Codes.
- Domänenereignisse (Bestellung bezahlt/erstattet/storniert, Tickets ausgestellt, Check-in, Termin verschoben/abgesagt) werden über die Job-Warteschlange an aktivierte Konnektoren zugestellt, mit Wiederholung je Konnektor.
- Versionierte Erweiterungspunkte für Add-ons (`wp_pass_captain_settings_defaults_v1`, `_settings_sections_v1`, `_admin_menu_v1`, `_report_*_v1`, `_export_*_v1`, `_scanner_adapters_v1`) mit Vertragstest; ein Integrationstest stellt sicher, dass der Kern ohne Add-on und ohne Netzwerkzugriff vollständig funktioniert. Dokumentation: `docs/EXTENSIONS.md`, `docs/HOOKS.md`.

### Steuerprofile, Einwilligungen, Käuferlimits, Preisregeln, Berichte
- Steuerprofile: versionierte Profile je Land und Steuerklasse (Entwurf → aktiv → archiviert, aktive Versionen unveränderlich). Je Bestellposition werden Steuersatz, Profil und Version gespeichert; Rechnungen, Gutschriften und Erstattungen verwenden diesen Stand. Steuerland je Event (Leistungsort), optionaler Rechnungshinweis. Profile sind technische Vorlagen und müssen steuerlich geprüft werden (`docs/TAX-PROFILES.md`).
- Einwilligungsfelder: Text, Version, Zeitpunkt und Quelle werden je Ticket protokolliert; Käufer können im Bestellportal widerrufen; CSV-Export „Pass Captain Einwilligungen“.
- Käuferlimits je Event, Termin oder Ticketart (siehe oben).
- Automatische Preisregeln (Frühbucher, Last-Minute, Nachfrage, Wochentag/Zeitfenster) für alle Termine eines Events; die Regel mit der höchsten Priorität gewinnt, Käufer sehen den Grund, die angewendete Regel wird in der Bestellposition gespeichert.
- Kampagnen: bis zu drei Codes pro Bestellung nach Priorität, nicht kombinierbare Codes, optionaler Höchstrabatt je Bestellung und maximaler Gesamtrabatt über Preisregeln und Codes; Code, Rabattart und Währung sind nach der ersten Einlösung gesperrt.
- Berichte: Vergleich mit Vorperiode oder Vorjahreszeitraum, Aufschlüsselung nach Kampagne; Rollen ohne Finanzrechte sehen keine Zahlungsdaten (auch nicht im CSV).

### Audit, Rollen, Zwei-Faktor und Supportzugriff
- Audit-Log-Ansicht mit Filtern und protokolliertem CSV-Export; protokolliert werden zusätzlich Rollen- und Capability-Änderungen, Exporte und (zusammengefasst, begrenzt je Stunde) lesende Zugriffe auf personenbezogene Daten.
- Einlasspersonal je Termin (ohne eventweiten Zugriff); Datenminimierung je Rolle: Einlass und Veranstalter sehen keine Zahlungsdaten, Finanzbuchhaltung keine Teilnehmerangaben, Support sieht personenbezogene Daten maskiert (Freigabe je Fall für 15 Minuten, protokolliert).
- Gleichzeitige Anmeldungen von Einlass-/Kassenkonten werden erkannt und protokolliert; optional nur eine aktive Anmeldung.
- Zwei-Faktor-Pflicht je Kategorie einstellbar (Einstellungen, Zugangsdaten, Exporte, Rollen, Massenaktionen, API-Keys), zentral vor der Aktion durchgesetzt.
- Supportzugriff: Administratoren vergeben zeitlich begrenzte Rechte mit Pflichtgrund; Vergabe, Widerruf und Ablauf stehen im Audit-Log.
- Check-out optional nur für bestimmte Rollen; Wiedereinlass nach Check-out mit Höchstzahl je Ticket (für Ticketarten ohne eigene Einlassregel).
- Sprachauswahl für Käufer im Checkout und im Bestellportal; E-Mails und Tickets folgen der gewählten Sprache.
- Multisite: Netzwerkübersicht (Events, Bestellungen, Umsatz 30 Tage, Systemzustand je Website) mit protokolliertem CSV-Bericht; Daten verschiedener Websites werden nie gemeinsam abgefragt.

### Barrierefreiheit und Tests
- Admin-Barrierefreiheit: zugängliche Namen für Auswahl- und Textfelder, Screenreader-Texte für leere Spaltenköpfe, größere Klickflächen und AA-Kontraste; axe-Prüfung aller Admin-Seiten als Release-Gate.
- Unit-Tests für alle Browser-Skripte (`node --test`) und Playwright-E2E-Tests für Checkout, Bestellportal, Web-Scanner, Offline-PWA und den WooCommerce-Block-Checkout (`docs/TESTING.md`).

### CI und Release
- Blockierende statische Analyse: PHP_CodeSniffer (WPCS 3, PHPCompatibilityWP) und PHPStan Level 5 gegen Baselines, `composer audit`, Versionskonsistenz-Prüfung.
- Testmatrix PHP 8.2/8.3/8.4 × MariaDB 10.6/11.4 und MySQL 8.0/8.4; Installationstest des gebauten ZIP in frischem WordPress (aktuell und 6.4) und mit WooCommerce (HPOS an/aus).
- Reproduzierbares Release-ZIP mit SHA-256-Prüfsumme; Releases veröffentlichen nur das in der CI getestete ZIP. Secret-Scan (gitleaks) für Historie und ZIP, wöchentliche Lasttests.
- Übersetzungen: alle neuen Texte in Englisch, Spanisch, Französisch, Italienisch und Niederländisch; neue Prüfung `build/check-i18n.php` (fehlende Übersetzungen, Platzhalter, veraltete `.mo`) und `build/merge-po.php`.

### WooCommerce: mehrere Rechnungssteller
- Warenkörbe mit Tickets verschiedener Rechnungssteller (Vermittler-Veranstalter/Plattform) werden standardmäßig schon beim Hinzufügen, im Warenkorb und an der Kasse abgelehnt – klassisch und im Block-Checkout (Store API). Alternativ (Einstellung „Gemischte Warenkörbe → Je Rechnungssteller aufteilen“) entsteht je Rechnungssteller eine eigene Pass-Captain-Bestellung mit eigener Rechnung; verbindliche Regeln für Gutscheine, negative/positive Gebühren, Versand und Rundung (größte Reste) in `docs/WOOCOMMERCE.md`.
- WooCommerce-Erstattungen (Position, Teilmenge, nur Betrag, vollständig, nach Terminabsage) werden den Teilbestellungen und ihren Tickets zugeordnet; ob eingecheckte Tickets erstattet werden dürfen, entscheidet die Einstellung `refund_after_checkin` (Standard „Erlauben“; bei „Nicht erlauben“ werden solche Erstattungen vor der Auszahlung abgelehnt), ohne sie die WooCommerce-Option „Eingecheckte Tickets erstatten“. Negative Gebühren mindern jetzt auch bei einem Rechnungssteller den Betrag der Pass-Captain-Bestellung.
- `docs/SALES-MODELS.md`: technisches Auszahlungsmodell (Belege, Geldfluss, Auszahlung außerhalb des Plugins).

### Robustheit bei Datenbankfehlern (Auditbefunde)
- Zahlungs-Webhooks: Ein Datenbankfehler beim Beanspruchen eines Ereignisses wird nicht mehr als Duplikat bestätigt, sondern mit HTTP 500 beantwortet, damit der Anbieter erneut zustellt. Storno und Vollerstattung per Webhook prüfen jeden Schritt, rollen bei Fehlern zurück und lassen den Webhook wiederholen.
- Ticketausgabe: alle Schreibvorgänge werden geprüft und bei Fehlern vollständig zurückgerollt; ein Wiederholungsjob gibt die Tickets später aus. Teilnehmerdaten der Bestellung werden erst gelöscht, wenn alle Zuordnungen gespeichert sind.
- Ticketzustellung: fehlgeschlagene Ticket-Mails werden automatisch wiederholt (neue Links per Nachsende-Vorlage); der Zustellstatus erscheint in der Bestellansicht.
- Nachsendung: Codes werden pro Empfänger erst nach erfolgreichem Versand ersetzt; parallele Nachsendungen können keine veralteten Links mehr zustellen.
- Erstattungen: Bearbeitung kann nur einmal beansprucht werden; die lokale Verbuchung läuft in einer Transaktion. Vom Anbieter ausgezahlte, aber lokal nicht verbuchte Erstattungen werden automatisch nachgebucht, ohne den Anbieter erneut aufzurufen.
- Job- und Nachrichten-Warteschlange: Einträge werden nur nach erfolgreicher Reservierung und Commit verarbeitet; keine doppelten Mails bei parallelen Workern.
- Schema-Upgrade: Version und Fingerprint werden erst gespeichert, wenn alle Tabellen und Spalten tatsächlich existieren; sonst Admin-Hinweis und automatische Wiederholung.

### Offline-Scanner
- Die Scanner-App ist übersetzbar: Texte kommen in der Sprache der Website (auch offline verfügbar), Datumsangaben und `lang` folgen der Sprache. Neuer öffentlicher Endpunkt `GET /wp-pass-captain/v1/app/offline/i18n`, Filter `wpcap_offline_scanner_strings` und `wpcap_offline_scanner_locale`.
- Die App fordert die Texte in der Gerätesprache an (`?locale=` aus `navigator.languages`); verfügbar sind Deutsch, alle mitgelieferten Übersetzungen, installierte Sprachpakete und die Website-Sprache. Das Manifest ist sprachneutral.

### Release und WordPress.org
- Releases werden nur noch gebaut, wenn der Tag zur Version passt, der Commit auf `main` liegt und die CI für genau diesen Commit grün war.
- CI: Wächtertest gegen Lizenz-/Update-Code im Kern-Plugin und offizieller Plugin Check gegen das gebaute ZIP.
- readme.txt auf Englisch mit Abschnitt „External services“; neue Dokumentation `docs/FREE-PREMIUM.md`, erweiterte `docs/EXTENSIONS.md` und `docs/HOOKS.md`.

### Neuer Name: Pass Captain
- Das Plugin heißt jetzt „Pass Captain“ (WordPress.org erlaubt kein „WP“ im Plugin-Namen). Hauptdatei `pass-captain.php`, Plugin-Ordner und ZIP `pass-captain`, Textdomain `pass-captain`, Übersetzungsdateien `languages/pass-captain-*.po/.mo`.
- Umstieg: die neue Version installieren und aktivieren. Ist die alte Kopie `wp-pass-captain/wp-pass-captain.php` noch aktiv, übernimmt die neue Kopie deren Aktivierung (auf Multisite auch die Netzwerkaktivierung) – ohne Deaktivierungs- oder Deinstallationsroutine der alten Kopie. Daten, Einstellungen und Zeitpläne bleiben erhalten. Danach kann die alte Kopie gelöscht werden: Ihre Deinstallation entfernt dabei nie Daten, auch wenn „Daten beim Löschen entfernen“ aktiv ist, und die dabei gelöschten Zeitpläne legt die neue Kopie beim nächsten Seitenaufruf neu an.
- Unverändert (öffentliche Schnittstellen und gespeicherte Daten): REST-Namensraum `wp-pass-captain/v1` (Webhooks, Scanner-Apps, Wallet), Blocknamen `wp-pass-captain/*`, Admin-Seite `wp-pass-captain`, Theme-Vorlagenordner `wp-pass-captain/`, Shortcodes `wp_pass_captain_*`, Hooks, Präfix `wpcap_`, Action-Scheduler-Gruppe `wp-pass-captain`.
- Eigene Übersetzungen unter `wp-content/languages/plugins/` müssen von `wp-pass-captain-*` in `pass-captain-*` umbenannt werden.

### Zusammenspiel mit WP Voucher Captain
- Gemeinsamer Scanner: neuer Dienst `wp_pass_captain_checkin_service_v1` (`Integrations\Vouchers\CheckinService`) – `recognizes()`, `preview()`, `check_in()` mit denselben Regeln, Sperren und Audit wie der eigene Web-Scanner; Recht `wpcap_checkin` wird im Dienst geprüft. Der Voucher-Captain-Scanner erkennt damit auch Pass-Captain-Tickets; Gutschein-Einlösung und Ticket-Check-in bleiben getrennte Aktionen.
- Einstellungen zeigen den Status der Integration (Voucher Captain aktiv/Version, Gutscheine im Checkout, Gutschein → Ticket, gemeinsamer Scanner) mit Links; im Menü stehen „Gemeinsamer Scanner ↗“ und „WP Voucher Captain ↗“, wenn Voucher Captain aktiv ist.
- Werden Tickets einer Gutschein-Umwandlung in Pass Captain storniert oder erstattet (Bestellung storniert/erstattet, Termin oder Event abgesagt), meldet Pass Captain das über die neue Action `wp_pass_captain_voucher_tickets_revoked_v1` (Einlöse-Schlüssel, verbleibende Tickets). WP Voucher Captain stellt den Gutschein damit wieder her. Ein Widerruf durch das Gutscheinsystem selbst wird nicht zurückgemeldet.

### Gutscheine im Checkout
- Erstattung, während die Gutschein-Belastung noch wiederholt wird: Der Gutschein-Anteil wird vorgemerkt und direkt nach der erfolgreichen Belastung erstattet. Ist die Bestellung bis dahin vollständig erstattet oder storniert, wird der Gutschein freigegeben statt belastet.
- Hat Voucher Captain eine Reservierung bereits freigegeben (z. B. abgelaufen während einer Überweisung), wird der Gutschein mit dem gespeicherten Angebot neu reserviert und belastet, statt die Bestellung als „nicht belastbar“ zu melden. Die Betragsprüfung bei der Neureservierung nutzt `applied_minor`.
- Gutschein anwenden ist atomar: Bestellung und Positionssteuern werden in einer Transaktion und nur bei unveränderter, offener Bestellung geschrieben; andernfalls wird die Reservierung freigegeben. Ein Wiederholungsversuch mit demselben Schlüssel wendet einen noch nicht gespeicherten Gutschein nach.
- Checkout: Nach einer Ablehnung (Gutschein, Zahlungsstart) beginnt das erneute Absenden einen neuen Bestellversuch; ein abgebrochener Versuch wird nicht mehr als „Bestellung angelegt“ gemeldet (409 `wpcap_checkout_closed`).

### Einlass
- Standard-Einlassfenster für Ticketarten ohne eigene Einlassregel: Einlass ab 2 Stunden vor Beginn bis zum Ende des Termins (ohne Ende: bis Ende des Veranstaltungstags). Einstellbar unter „Einlass / Scanner-Geräte“ (`default_entry_window`, `entry_opens_before_minutes`, `entry_closes_after_minutes`); Ticketarten mit eigener Einlassregel bleiben unverändert.
- Web-Scanner offline: Codes werden nicht mehr ungeprüft vorgemerkt. Angezeigt wird „NICHT GEPRÜFT“; erst nach der ausdrücklichen Entscheidung „Nach Sichtprüfung einlassen“ wird der Scan für die spätere Prüfung gespeichert. Ungültige Codes werden sofort abgelehnt.

### Datenschutz
- Löschanfragen für Bestellungen mit gültigen Tickets für kommende Termine werden zurückgestellt (Art. 17 Abs. 3 DSGVO, Vertragserfüllung): Die Tickets bleiben gültig, der Admin wird per E-Mail informiert, und nach dem letzten Termin wird die Bestellung automatisch anonymisiert (stündlicher Lauf, Option `wpcap_deferred_erasures`, Filter `wpcap_privacy_defer_upcoming`). Bisher wurden solche Tickets sofort entwertet.

### WP Affiliate Captain
- Ohne Marketing-Einwilligung bleibt der Partnerlink der laufenden Shop-Sitzung erhalten (er setzt kein Marketing-Cookie); nur der Empfehlungs-Cookie braucht die Einwilligung „marketing“. Bisher ging bei abgelehntem Marketing auch die cookie-freie Zuordnung verloren. Der Filter `wpcap_affiliate_tracking_allowed` erhält als zweites Argument die Methode (`cookie`, `link`, `app`).
- Provisionsbasis bei Mehrzweckgutscheinen (Zahlungsmittel): Abgezogen wird nur noch der USt-Anteil des tatsächlich bezahlten Betrags. Bisher wurde die volle USt der Position vom reduzierten Zahlbetrag abgezogen, die Basis war dadurch zu niedrig (Beispiel 119 € Ticket, 15 € Gutschein: 85,00 € statt 87,39 €).

## 1.4.0 – 2026-11-12

### Verkaufsmodelle: Eigenhändler oder Vermittler
- Verkaufsmodell je Veranstalter: „Eigenhändler“ (Standard, Verkauf im eigenen Namen) oder „Vermittler“ (Verkauf im Namen und für Rechnung des Veranstalters) mit Rechnungsangaben (rechtlicher Name, Land, Steuernummer, USt-IdNr.-Prüfdatum, Rechnungspräfix, Kleinunternehmer-Hinweis).
- Vermittler: Kundenrechnungen und Rechnungskorrekturen im Namen des Veranstalters mit eigenem lückenlosem Nummernkreis je Veranstalter und Hinweis „Vermittelt durch …“.
- Bestellungen mit Tickets verschiedener Rechnungssteller werden im Checkout abgelehnt; bestehende gemischte Bestellungen erhalten eine klare Fehlermeldung statt einer fehlerhaften Rechnung.
- Veranstalter-Abrechnung wird je nach Modell zur „Provisionsrechnung“ (inkl. Reverse Charge für Veranstalter im EU-Ausland) oder zu „Gutschrift“ (eigener Nummernkreis GU) plus Rechnung über Plattformgebühren mit Verrechnung.
- Plattform-Einstellungen: Steuernummer und Land des Rechnungsstellers; Buchhaltungsexport mit Spalten Verkaufsmodell und Rechnungssteller. Siehe docs/SALES-MODELS.md.

### Rechnung auf Firma/Adresse
- Checkout, Kasse und Bestellportal: optionale Rechnungsangaben (Firma, Name, Anschrift, Land, UID-Nummer, eigene Referenz) mit Prüfung (UID-Format je EU-Land, PLZ-Format für AT/DE/CH/LI/NL/PL) und barrierefreien Fehlermeldungen; Korrektur im Bestellportal bis zur Rechnungsausstellung.
- Name und Anschrift sind ab einem Bestellbetrag über 250 € Pflicht (Einstellung, 0 = aus; deckt DE § 33 UStDV und AT § 11 UStG ab).
- Rechnungen und Rechnungskorrekturen (HTML und PDF) enthalten die Empfängerangaben; WooCommerce-Rechnungsdaten (inkl. UID aus gängigen Meta-Feldern) werden übernommen; Bestelldetails im Backend zeigen sie.
- Rechnungsdaten sind im Datenschutz-Export enthalten und werden bei Löschanfragen entfernt; ausgestellte Rechnungen behalten ihre Empfängerangaben. Datenbankschema 3.5.0.

### Gutscheine: Einzweck und Mehrzweck
- Unterscheidung Einzweck-/Mehrzweckgutschein (Art. 30a/30b MwStSystRL, § 3 Abs. 13–15 UStG, § 4 Abs. 9 öUStG); die Art kommt aus WP Voucher Captain, dem Filter `wpcap_voucher_purpose` oder der Einstellung „Gutscheine standardmäßig als Mehrzweckgutschein behandeln“ (Standard: Mehrzweck).
- Mehrzweckgutscheine sind ein Zahlungsmittel: Ticketpreis und Umsatzsteuer bleiben voll, nur der Zahlbetrag sinkt (bisher wurde die USt zu niedrig ausgewiesen). Einzweckgutscheine bleiben wie bisher.
- Rechnungen mit Abschnitt „Zahlungen“ (Gutschein mit maskiertem Code, bezahlter Restbetrag); Bestelldetails und Bestellportal zeigen Gesamtbetrag, Gutscheinanteil und Zahlbetrag.
- Erstattungen werden anteilig nach Ticketwert auf Zahlungsanbieter und Gutschein aufgeteilt; Rechnungskorrekturen umfassen beide Teile mit voller USt.
- Ticketdienst (Gutschein → Tickets): Mehrzweckgutscheine stellen Tickets zum vollen Preis mit USt aus; bei Einzweckgutscheinen wird der Gutscheinwert für Abrechnungen gespeichert. Siehe docs/VOUCHERS.md.

## 1.3.0 – 2026-11-12

Korrekturen und Lücken aus einer Experten-Prüfung (Sicherheit, Ticketing-Betrieb, UX, Steuer, Datenschutz, Architektur).

### Sicherheit und Erstattungen
- Bereits übertragene Tickets können über den Bestelllink des Käufers nicht mehr neu zugewiesen werden; ein Personenwechsel per Zuweisung ist pro Ticket nur einmal möglich (Filter `wpcap_max_holder_changes`), und danach ist keine weitere Übertragung mehr möglich.
- Erstattungen sperren die Bestellung; Tickets mit wartender oder laufender Erstattung können nicht erneut erstattet werden; vor jeder Auszahlung wird erneut geprüft (sonst Fehlschlag ohne Zahlungsanbieter-Aufruf); die Freigabegrenze gilt für die Summe aller Erstattungen einer Bestellung.
- PDF-Rechnungsdownload erstellt neue Rechnungsnummern nur mit gültigem Nonce (CSRF).
- Ticket-PDFs und Apple-Wallet-Dateien werden in privaten temporären Verzeichnissen erzeugt und sofort gelöscht.
- Beim Entfernen der letzten Check-in-Zuweisung wird das Recht `wpcap_checkin` wieder entzogen.

### Abläufe
- Eventabsage läuft pro Termin über die reguläre Terminabsage (automatische Erstattung, Module werden informiert, eine Absage-E-Mail je Käufer); durch Absage widerrufene, bezahlte Tickets bleiben erstattungsfähig.
- Gruppenbuchungen: Höchstmenge pro Position richtet sich nach „Max. pro Bestellung“ (statt fest 10, Obergrenze 100).
- Neue Einstellung „Einlass-Tageswechsel“ für Festivals und Nachtveranstaltungen.
- Einzelne Tickets können neu ausgestellt werden; „Tickets erneut senden“ lässt eingecheckte Tickets unverändert.
- Abgelaufene Reservierungen werden einzeln verarbeitet; eine zwischenzeitlich bezahlte Bestellung wird nicht mehr fälschlich storniert.
- Checkout-Ratenbegrenzung hinter Proxy/CDN über den Filter `wpcap_client_ip`.

### Kundenerlebnis
- Bestätigung nach der Bestellung mit kurzer Bestellnummer, Betrag und bei Überweisung Empfänger, IBAN, BIC, Verwendungszweck und Zahlungsfrist – auch in der Bestell-E-Mail (neue Einstellungen für Bankdaten, Platzhalter `{payment_instructions}`, `{order_reference}`).
- Barrierefreie Checkout-Fehler: Fehlerübersicht mit Links, Meldungen direkt am Feld, auch für Serverfehler.
- Bestellübersicht: kurze Bestellnummer, „Tickets erneut senden“, Sicherheitsabfrage vor dem Übertragen, Zahlungsinformationen bei offener Überweisung.
- Eventkarte: klare Reihenfolge und Beschriftung, Datumsformat der Website, Zeitzone nur bei Abweichung, Preise mit Währungssymbol.

### Steuer und Belege
- Rechnungen mit Netto, USt. und Brutto je Steuersatz; Korrekturbelege heißen „Rechnungskorrektur“; Belegnummern-Jahr nach Ortszeit.
- Kasse: deutlicher Hinweis, dass sie keine Registrierkasse nach RKSV (AT) bzw. KassenSichV/TSE (DE) ist.

### Datenschutz
- Täglicher Löschlauf setzt Aufbewahrungsfristen durch (Teilnehmerangaben, Offline-Namen, Wallet-Registrierungen, unbezahlte Bestellungen, versandte Nachrichten, Einlassprotokoll, Audit-Log); Finanzdaten bleiben.
- Datenschutz-Löschung und -Export vollständig; Tickets werden über den Ticketdienst widerrufen (Wallet, Offline-Pakete, Webhooks informiert); Rechnungen bleiben gemäß Aufbewahrungspflicht. Behoben: Löschung schlug bei Bestellungen mit mehreren Tickets fehl.
- Vorschlag für die Datenschutzerklärung je nach aktivierten Diensten.
- Affiliate-Cookie nur nach Einwilligung (WP Consent API); Web-Scanner-Offline-Warteschlange je Benutzer, 24 Stunden.
- IP-Hashes mit seitenspezifischem Schlüssel.

### Betrieb und Release
- Datenbank-Upgrade nicht mehr bei jedem Seitenaufruf, sondern im Admin/Cron/WP-CLI hinter einer Sperre (Schema 3.4.0 mit neuen Indizes).
- Mail-Warteschlange mit Zeitbudget (bis 500 Nachrichten pro Lauf, sofortige Folge-Läufe); Jobs, Webhooks und SMS mit Zeitbudget.
- Multisite: Tabellen gelöschter Sites werden entfernt.
- Release nur nach erfolgreicher CI; ZIP aus `git archive` mit `.distignore`; neue `readme.txt`; vollständiger Plugin-Header.

## 1.2.0 – 2026-11-12

### Gutschein in Ticket umwandeln (Voucher Captain)
- Neuer Dienst `wp_pass_captain_ticket_service_v1` (Klasse `Integrations\Vouchers\TicketService`): `issue_tickets( source, idempotency_key, target, recipient )` stellt für einen eingelösten Gutschein Tickets einer Ticketart aus – als kostenlose Bestellung „bezahlt per Gutschein“ über den normalen Bestellweg (Kontingent, Teilnehmerfelder, Ticket-Mail). Gleicher Schlüssel ⇒ gleiche Bestellung und Tickets; ausverkauft oder ungültig ⇒ Fehler ohne Tickets; vorübergehende Fehler sind wiederholbar (`retryable`).
- `revoke_tickets( idempotency_key, reason )` storniert die Tickets einer Umwandlung (eingecheckte bleiben gültig), `get()` liefert den Stand, `targets()` die künftigen Ticketarten für Auswahllisten. Jede Umwandlung steht im Audit-Log (`voucher_tickets_issued`) mit Gutschein-Referenz.
- Gutscheine für einen frei wählbaren Termin: Ziel `event_id` (optional `ticket_name`) statt einer festen Ticketart; `options()` liefert die wählbaren künftigen Termine, `events()` die Veranstaltungen für Auswahllisten. `issue_tickets()` prüft, dass die gewählte Ticketart zu einem künftigen Termin der Veranstaltung (und zum Ticketnamen) gehört.
- Ausverkaufte Termine werden nicht mehr angeboten (`options()` berücksichtigt die Ticketanzahl des Gutscheins, `targets()` liefert `available`). `fields( ticket_type_id )` nennt die Teilnehmerfelder, damit das Einlöseformular sie abfragen kann.

### Veranstalter statt „Partner“
- „Partner“ wird für Veranstalter nicht mehr verwendet: Der Rollenname „Pass Captain Partner“ wird beim Update automatisch in „Pass Captain Veranstalter“ umbenannt (ein vom Betreiber geänderter Name bleibt; die Umbenennung läuft erst bei `init`, damit Übersetzungen nicht zu früh geladen werden). Die Doku spricht durchgehend von Veranstaltern, Veranstalter-API und Veranstalter-Abrechnungen; „Partner“ meint nur noch die Affiliate-Partner von WP Affiliate Captain. REST-Pfade, Header, Fehlercodes, Tabellen und der Rollen-Slug bleiben unverändert.

## 1.1.0 – 2026-11-12

### Sprachen
- Neue Übersetzungen: Französisch (fr_FR), Italienisch (it_IT), Spanisch (es_ES) und Niederländisch (nl_NL), jeweils vollständig (1839 Texte); Englisch ergänzt. Platzhalter automatisch geprüft.

### WooCommerce: Serien-Preisstufen im Shop
- Neue Einstellung „Pass-Captain-Preise (z. B. Serien-Preisstufen) im WooCommerce-Warenkorb verwenden“ (WooCommerce → Einstellungen → Produkte, standardmäßig aus): Preise verknüpfter Ticketprodukte kommen aus Pass Captain (Frühbucher-, Last-Minute-, Nachfrage- und Wochentagsstufen) – im klassischen Warenkorb, Mini-Warenkorb, Checkout sowie im Warenkorb- und Checkout-Block.
- Produktseite und Warenkorb zeigen den wirksamen Preis mit Stufenbezeichnung und „statt“-Preis; bei netto gepflegten Shoppreisen wird mit dem Steuersatz des Produkts umgerechnet.
- Ändert sich der Preis zwischen Warenkorb und Bestellung (z. B. Frühbucherfrist abgelaufen), wird neu berechnet, ein Hinweis angezeigt und das Absenden einmalig angehalten.
- Neuer Filter `wpcap_woocommerce_use_core_prices`.

### E-Mail-Vorlagen
- Neu: E-Mail-Vorlagen (Pass Captain → E-Mail-Vorlagen): Betreff und Text aller Kunden-Mails (Tickets, Neuversand, Bestellzugang, Übertragung, Zuweisung, Erstattung, Absagen, Terminänderung, Warteliste, Mitgliedspass) je Sprache anpassbar – mit Platzhalter-Übersicht, Live-Vorschau, „Testmail senden“ und „Auf Standard zurücksetzen“. Siehe docs/MAIL-TEMPLATES.md.
- Optionaler HTML-Modus mit schlichtem Layout (Logo, Akzentfarbe, Fußzeile) und automatisch mitgesendeter Textversion.
- Kunden-Mails werden in der Sprache der Bestellung gesendet (Vorlage der Sprache → Standardsprache → eingebauter Text).
- Zentraler Mailer `PassCaptain\Mail\Mailer` mit `wpcap_mail_types`, `wpcap_mail`, `wpcap_mail_before_send`, `wpcap_mail_layout_html`; `wpcap_ticket_mail` und `wpcap_ticket_code_mail_section` funktionieren unverändert. Ohne eigene Vorlagen bleiben alle Texte gleich.

### WP Affiliate Captain
- Oberfläche: Das Modul „Partner“ (Veranstalter/Wiederverkäufer mit Abrechnungen) heißt jetzt **Veranstalter** bzw. **Veranstalter-Abrechnungen**, damit es nicht mit den Affiliate-Partnern verwechselt wird (de und en_US: „Organizer“). REST-Pfade, Header, Fehlercodes, Tabellen und Rollen-Slug bleiben unverändert.
- Optionaler Adapter `src/Integrations/AffiliateCaptain/Adapter.php` für WP Affiliate Captain (Vertrag v1). Aktiv nur, wenn Affiliate Captain mit kompatibler Vertragsversion läuft; nutzt ausschließlich vorhandene Hooks, keine Kernänderungen.
- Bezahlte Bestellungen (Online-Checkout, Kasse, Abendkasse, Warteliste) werden als Verkauf `pass-captain` gemeldet; Basis je Position ist der tatsächlich bezahlte Betrag (nach Kampagnenrabatt und eingelöstem Gutschein) ohne die gespeicherte USt. Provision bleibt bis nach dem Termin gesperrt.
- Erstattungen (auch Teilerstattungen und Erstattungen abgesagter Termine) kehren den Nettoanteil der erstatteten Tickets um; Vollerstattung und Storno bezahlter Bestellungen kehren den Rest um. WooCommerce-Bestellungen werden nicht gemeldet – die erfasst Affiliate Captain selbst.
- Checkout: optionales Feld „Partnercode“ (geprüft, Fehlversuche je Client begrenzt); Partnerlink/Referral-Cookie wird mit der Bestellung gespeichert. Kasse und Abendkasse übernehmen nie das Referral der Mitarbeitenden.

### Gutscheine (Voucher Captain)
- Verspätete Zahlung nach Ablauf: War die Gutschein-Reservierung beim Ablauf der Bestellung schon freigegeben, wird der Gutschein bei der verspäteten Zahlung mit dem gespeicherten Angebot neu reserviert und belastet. Bisher wurde das Ticket mit Rabatt ausgestellt, ohne den Gutschein zu belasten.
- Fehler beim Belasten des Gutscheins werden nicht mehr nur protokolliert: Vorübergehende Fehler (Status ≥ 500, `vcap_temporary_failure`, Anbieter nicht aktiv) werden über die Job-Warteschlange (`voucher_commit`) mit denselben Schlüsseln wiederholt. Endgültige Fehler und der letzte Versuch markieren die Bestellung (`commit_failed` im Gutschein-Snapshot, Hinweis in der Bestellansicht, Audit-Eintrag, E-Mail an den Admin). Die Tickets bleiben gültig.
- `Checkout::commit()` gibt `true` (erledigt) oder `false` (Wiederholung geplant) zurück; neu: `Checkout::handle_job()` und `Checkout::voucher_state()`.

### Datenbank
- MySQL: Die Prüfung „Transaktion noch offen?“ fragt `@@in_transaction` nur noch bei MariaDB ab (einmal pro Anfrage per `VERSION()` erkannt). Unter MySQL gibt es diese Variable nicht; bisher schrieb jede Bestellung dort die Zeile „Unknown system variable 'in_transaction'“ ins Fehlerprotokoll. Das Verhalten unter MySQL bleibt gleich (Zustand unbekannt).

## 1.0.0 – 2026-11-11

### Serien: Preisstufen und Kontingente
- Serientermine: Preisstufen je Serie – Frühbucherpreis (bis Datum/Uhrzeit in Ortszeit), Last-Minute-Preis (ab X Stunden vor Beginn), nachfrageabhängige Stufen (ab X % verkauft) sowie Wochentags-/Zeitfensterpreise; fester Preis oder prozentuale Änderung, optional nur für eine Ticketart, mit Prioritäten.
- Gemeinsame Kontingente (Pools) über alle Termine einer Serie, für alle oder eine Ticketart; race-frei in der Bestell-Transaktion gesperrt, automatische Freigabe bei Ablauf, Storno und Erstattung.
- Eventseite, Checkout, Kasse, öffentliche REST-Eventliste und Mollie-Zahlartenauswahl nutzen den tatsächlich berechneten Preis (mit Stufenbezeichnung und „statt“-Preis).
- Neue Entwickler-Hooks: `wpcap_ticket_unit_price`, `wpcap_ticket_price_label`, `wpcap_reserve_capacity`, `wpcap_ticket_availability`, `wpcap_capacity_released`.
- Bestellungen sperren Ticketarten und Termine in fester Reihenfolge vor der Kontingentprüfung (auch bei verspäteter Zahlung).

### Partner-Zugang und Abrechnung
- Neues Modul „Partner“: Veranstalter/Wiederverkäufer mit Rechnungsdaten, Provisionsmodell (Prozent vom Netto und/oder feste Gebühr pro Ticket, USt auf die Plattformgebühr, Erstattungsregel), Zuordnung von Events und Benutzern.
- Partner-API (`/wp-json/wp-pass-captain/v1/partner/…`, nur lesend) mit API-Keys: Secret nur einmal sichtbar und nur als Hash gespeichert, Scopes, Event-Einschränkung, IP-Allowlist, Ablaufdatum, Widerruf, Ratenlimit, Sperre nach Fehlversuchen; E-Mail-Adressen nur mit `attendees:read`. Siehe docs/PARTNER-API.md.
- Monatliche Partner-Abrechnungen (Entwurf → abgeschlossen mit lückenloser Nummer → ausgezahlt), unveränderlich nach Abschluss; spätere Erstattungen/Nachzahlungen als Korrektur bzw. Nachtrag in der nächsten Periode; Druckansicht und CSV.

### Offline-Scanner (PWA)
- Offline-Scanner als installierbare Web-App: gekoppelte Geräte laden pro Termin ein Ed25519-signiertes Prüfpaket (nur SHA-256-Hashes der Ticketcodes, Status, Tickettyp, erlaubte Bereiche), lassen ohne Netz ein und synchronisieren später; Konflikte werden in der App angezeigt.
- Offline-Scannen muss pro Event freigegeben werden: maximale Offline-Dauer, Regel für Doppelscans („zur Prüfung“ oder „erste Synchronisierung gewinnt“), optional Teilnehmernamen.
- Admin-Seite „Offline-Scanner“: Freigabe und Regeln, Geräte-Zuweisung je Event oder Termin, Schlüsselrotation mit Übergangsfrist.
- App-API `GET /app/offline/keys`, `/app/offline/occurrences`, `/app/offline/package` (Voll- und Delta-Pakete inkl. widerrufener Tickets). Siehe docs/OFFLINE-SCANNER.md.
- Offline-Scans nach Ablauf der maximalen Offline-Dauer erscheinen als Einlass-Konflikt „Offline-Dauer überschritten“.

### Last- und Barrierefreiheitstests
- Lasttests (`tests/load/run.php`, `tests/load/http.php`): parallele Käufer mit begrenztem Kontingent, gleichzeitige Check-ins desselben Tickets von mehreren Geräten und Webhook-Bursts mit Duplikaten; prüfen Überverkauf, Idempotenz und Fehlerquote, messen p50/p95/p99. Das kleine Profil läuft in CI als Regressionstest.
- Barrierefreiheitstests (`tests/a11y/`): axe-core + Playwright für Checkout, Ticketseite, Bestellportal, Warteliste, Scanner und zentrale Admin-Seiten sowie ein Tastatur-Durchlauf des Checkouts. Siehe docs/TESTING.md.
- Barrierefreiheit: gültiges `lang`-Attribut (`de-DE`), Beschriftungen für Filter, Zeitzone, Währung, Erstattungs-Checkboxen und Kassenmengen, besserer Kontrast für Codes in Links, größere Klickflächen bei den Zahlarten, korrekte Überschriftenebenen (Eventkarte per Filter `wpcap_event_card_heading_level`, Sitzplatzauswahl); Tastaturfokus bleibt nach Checkout-Absenden und im Scanner erhalten.

### Korrekturen
- Behoben (gefunden durch einen sporadischen CI-Fehler): Wurde die Bestell-Transaktion serverseitig zurückgerollt (Deadlock/Lock-Timeout), liefen die restlichen Anweisungen ohne Sperre im Autocommit weiter – im Extremfall Überverkauf. Bestellanlage und Zahlungsbestätigung prüfen jetzt jede Anweisung und den Transaktionsstatus und melden dann einen wiederholbaren Fehler (503); das Aufräumen abgelaufener Reservierungen innerhalb der Sperre (Deadlock-Quelle) entfällt.
- Behoben (gefunden durch Lasttest): Gleichzeitige Check-ins verschiedener Tickets konnten einen Datenbank-Deadlock auslösen; der Scanner meldete dann „eingelassen“, obwohl die Buchung zurückgerollt war, und das Ticket blieb gültig. Die Admission-Zeile wird nicht mehr per Gap-Lock gesperrt, und jeder Schreibschritt wird geprüft; bei einem Fehler wird zurückgerollt und ein wiederholbarer Fehler (503) gemeldet.
- Erstattungen enthalten die Umsatzsteuer der erstatteten Positionen (vorher anteilig über die ganze Bestellung, falsch bei gemischten Steuersätzen); wirkt auf Gutschriften, Berichte und Partner-Abrechnungen.

## 0.9.0 – 2026-11-11

### Abnahmetest in echtem WordPress/WooCommerce
- Ticket-Mails zeigen die Startzeit in der Zeitzone des Termins (vorher UTC mit lokalem Zonennamen).
- Kundenkommunikation nutzt den Seitennamen statt „WP Pass Captain“; Status werden übersetzt angezeigt.
- Kundenportal: Namens-/Angabenkorrektur behält den Ticketcode; neue Inhaber:in erhält einen neuen Code, Käufer:in wird informiert.
- Sitzplatz auf PDF, Ticketseite und im Portal; Reservierungsdauer in Stunden/Tagen.
- Bestelldetail: Rechnung erstellen/anzeigen, Gutschriften; Einstellungsseite ohne Rohschlüssel.
- Erstattungsbestätigung per Mail; Rollen werden erst bei `init` registriert (kein Textdomain-Hinweis).
- Abendkasse: Migration für `order_id NULL`, Teilnehmerfelder je Ticket, kein Beleg für ungebuchte Verkäufe.
- WooCommerce: Teilnehmerfelder im klassischen und Block-Checkout, Teil-Erstattungen mit Buchung und Gutschrift (`Refunds::record_external()`), Nachnahme stellt erst bei „Abgeschlossen“ aus, Mengenbegrenzung in der Store API.

### P2: Erweiterte Eventfunktionen, Einlass, Vor-Ort-Verkauf

Alle P2-Funktionen sind Module (`src/Modules/`) mit eigenen Tabellen, Einstellungen und Admin-Seiten unter **Pass Captain**.

- **Saalpläne:** grafischer SVG-Editor (Reihen, Blöcke, Tische, Stehbereiche, Zonen, Rollstuhlplätze), versionierte Layouts mit Schutz verkaufter Plätze, barrierearme Platzwahl im Checkout, atomare Platzreservierung, Platz auf Ticket und in der Mail.
- **Serientermine:** täglich, wöchentlich (Wochentage), monatlich (Tag / n-ter Wochentag), Datumsliste; zeitzonen- und sommerzeitsicher, mit Ausnahmen, Vorschau und idempotenter Erzeugung inkl. Ticketkategorien und Verkaufsfenstern.
- **Einlass:** Regeln pro Ticketkategorie (einmalig, täglich, mehrtägig, mehrfach, Wiedereinlass), Einlasszeitfenster, Check-out, Einlassbereiche für Ticketkategorien, Personal und Geräte, Klärungsliste für Offline-Doppeleinlässe, Gerätestatus. Mobile-API 1.1.
- **Abendkasse:** Verkauf mit gemeinsamem Kontingent, Zahlarten, lückenlose Bons, 80-mm-Druck, Kassensitzungen, Storno mit Freigabe; Verkauf auch nach Beginn bis Terminende.
- **Druck:** PDF-Vorlagen für Ticket, Badge, Namensschild, Armband; Sammeldruck; Teilnehmerliste (Druck/CSV, rollenbasiert); Rechnungs- und Gutschrift-PDF.
- **Berichte:** Umsatz mit USt pro Satz und Erstattungen, Auslastung, Einlassquote und -verlauf, Kasse vs. online; CSV und SVG-Diagramme.
- **Wallet:** Apple Wallet (signierte Pässe, Update-Webservice, APNs-Push) und Google Wallet (Speichern-Link, Status-Updates).
- **Kalender:** Shortcode/Block, iCal-Download und -Feed, `.ics` in der Ticket-Mail.
- **Erinnerungen:** mehrere Zeitpunkte vor Beginn, über die Nachrichtenwarteschlange, mit Vorlagen.
- **SMS:** Twilio und generischer HTTP-Anbieter, nur mit dokumentierter Einwilligung, Zustellstatus.
- **Mollie:** weiterer Zahlungsanbieter (SEPA, Klarna, EPS, iDEAL, Bancontact u. a. je nach Land/Währung).
- **Webhooks:** signierte, versionierte ausgehende Webhooks mit Wiederholungen; `docs/WEBHOOKS.md`, alle Entwickler-Hooks in `docs/HOOKS.md`.
- **Kern:** Modulsystem; neue Hooks `wpcap_order_creating`, `wpcap_ticket_created`, `wpcap_ticket_mail`, `wpcap_ticket_code_rotated`, `wpcap_order_refunded`, `wpcap_ticket_checked_out` u. a.; alle Widerrufswege lösen `wpcap_ticket_revoked` aus.
- **Abendkasse – Korrekturen:** Bestehende Installationen werden migriert (`wpcap_boxoffice_sales.order_id` wird `NULL`-fähig, alte `0`-Einträge werden `NULL`, verwaiste Verkaufs-Reservierungen gelöscht; dbDelta ändert `NULL`/`NOT NULL` nicht). Bisher blockierte ein fehlgeschlagener Verkauf jeden weiteren mit „Duplicate entry '0' for key 'order_id'“. Belege gibt es nur noch für gebuchte Verkäufe.
- **Abendkasse – Teilnehmerangaben:** Das Verkaufsformular zeigt je Ticket Name, E-Mail und die Teilnehmerfelder der Ticketart (gleiche Definition wie im Online-Checkout); Pflichtfelder (z. B. „Firma“) lassen sich damit erfüllen. Für Ticketarten mit Saalplan weist die Kasse die besten freien Plätze automatisch zu und sagt das im Formular an.

### Scanner-App-API und Einschränkungen aufgehoben

- **Mobile Scanner-API** (`/wp-json/wp-pass-captain/v1/app/`): Gerätekopplung per Einmal-QR, Bearer-Gerätetokens (ablaufend, eventgebunden, widerrufbar, nur als Hash gespeichert), Prüfen, Einlass mit Idempotenz-Schlüssel, Rücknahme, Offline-Synchronisierung mit Konfliktkennzeichnung, Termin-Statistik. Admin-Seite **Scanner-Geräte**. Spezifikation in `docs/MOBILE-API.md`.
- **Gemeinsamer Check-in-Dienst** für Web, App und Offline-Sync. Abgelehnte Zweitscans werden als `already_checked_in` protokolliert statt als `checked_in`; das hatte die Einlasszahlen in Berichten erhöht.
- **Kamera-Scan auf iOS/Safari/Firefox** über das mitgelieferte jsQR, falls keine native Barcode-Erkennung vorhanden ist.
- **Absage einzelner Termine**: offene Bestellungen werden storniert, Tickets widerrufen, Inhaber informiert; optional automatische Erstattung bezahlter Bestellungen (`auto_refund_cancelled`). WooCommerce-Bestellungen erhalten eine Notiz.
- **Englische Übersetzung** (`en_US`) und ein abhängigkeitsfreier `.mo`-Compiler (`build/compile-mo.php`).
- Schema 3.3.0 (Geräte-Kopplungen, Gerätefelder, Check-in-Quelle/Idempotenz).

### Verkaufsfähigkeit (Blocker und P1)

- **Online-Zahlung:** Stripe Checkout und PayPal (Orders v2) mit signierten, idempotenten Webhooks, Erstattung über den Anbieter und Hinweis nach Rückkehr vom Zahlungsanbieter. Webhook-URLs: `/wp-json/wp-pass-captain/v1/payments/stripe/webhook` und `…/paypal/webhook`. Tickets werden nur bei passendem Betrag und Währung ausgestellt.
- **Admin-Oberfläche:** Events, Termine, Ticketkategorien, Teilnehmerfelder, Personal, Bestellungen (Details, Bezahlt-markieren, Storno, Erstattung, Freigaben), Tickets (Suche, Widerruf, Check-in rückgängig), Check-in-Protokoll und Einstellungen.
- **Checkout:** mehrere Ticketkategorien pro Bestellung, Teilnehmerfelder je Ticket, Zahlungsartwahl, Rabatt- und Gutscheincode, Verbraucherinformationen und Pflicht-Bestätigung der Bedingungen; die akzeptierte Textversion wird gespeichert.
- **Erstattungen:** positionsbezogen, idempotent, mit Zweitfreigabe ab Schwellenwert und optional verpflichtender Zwei-Faktor-Anmeldung.
- **Steuern und Belege:** USt-Satz je Ticketkategorie, Berechnung je Position aus Bruttopreisen, Rechnungen mit USt und Verkäuferdaten, Gutschriften je Erstattung, Druckansicht, CSV-Buchhaltungsexport.
- **Voucher Captain:** Gutschein-Einlösung im Checkout (Angebot, Reservierung, Verbuchung, Freigabe, anteilige Rückbuchung).
- **Self-Service und Kommunikation:** Teilnehmerdaten bis zu einer Frist änderbar, Benachrichtigung bei Terminverschiebung, PDF-Tickets als E-Mail-Anhang.
- **Rollen:** Ticket-Administration, Veranstaltungsleitung, Vor-Ort-Kasse, Einlass, Partner, Finanzbuchhaltung, Support.
- **Betrieb:** `uninstall.php` (Daten bleiben standardmäßig erhalten), Übersetzungsvorlage `languages/wp-pass-captain.pot`, Schema 3.2.0.

### Fehlerbehebungen nach Code-Review

- Warteliste: Die Annahme eines Angebots legt direkt eine Bestellung mit Reservierung an. Ist das Ticket inzwischen weg, bleibt das Angebot bis zum Ablauf offen. Die Anmeldung antwortet immer gleich und verrät nicht mehr, ob eine Adresse schon eingetragen ist; neue Einträge erhalten eine Bestätigungsmail mit Position. Angebotsablauf in der Mail in Ortszeit.
- Manuelle Zahlungsbestätigung nach Ablauf der 10-Minuten-Reservierung prüft das Kontingent erneut, statt die Bestätigung abzulehnen.
- Checkout und Servicebuchungen haben zusätzlich ein Limit pro IP, damit wechselnde E-Mail-Adressen das Limit nicht umgehen.
- Gutenberg-Block hat ein Editor-Skript (Vorschau über ServerSideRender) und erscheint im Inserter.
- `composer.lock` (Plattform PHP 8.2) für reproduzierbare Release-Builds.

- Sicherheit: Der öffentliche Checkout übernimmt nur noch `email`, `items`, `idempotency_key` und `campaign_codes`. Vorher ließen sich über die REST-API Preise auf 0 setzen oder Kontingente unbegrenzt blockieren.
- Idempotency-Keys von Kasse (POS) und WooCommerce sind vom öffentlichen Checkout getrennt; gleichzeitige Anfragen mit demselben Key liefern die bestehende Bestellung statt eines Fehlers.
- POS-Verkäufe melden den tatsächlichen Bestellstatus und unterliegen nicht mehr dem anonymen Checkout-Limit.
- Eventbezogene Rechte (Event verwalten, Bestellungen/Kasse, Berichte) lassen sich zuweisen; vorher erhielt die Veranstaltungsleitung überall 403.
- Datenschutz-Löschung: keine übersprungenen Datensätze ab der 51. Bestellung mehr, und Löschanfragen von Teilnehmenden verändern nicht mehr die Bestellung anderer Käufer.
- Scanner: Kamera-Scan funktioniert (vorher Fehler beim Start), Scans bei Netzwerkfehlern werden zwischengespeichert, keine verlorenen Scans während der Synchronisierung, kein Doppelscan desselben QR-Codes.
- Teilweise erstattete und widerrufene Tickets geben ihr Kontingent frei (neue Spalte `refunded_quantity`, Schema 3.1.0).
- WooCommerce: exakte Positionssummen statt gerundeter Stückpreise, Nachkommastellen nach Währung statt Anzeigeeinstellung, Admin-Hinweis zu `needs_review` nur einmal, persönlicher Bestelllink nur bei der ersten Ausstellung.
- Rechnungsnummern werden in derselben Transaktion wie die Rechnung vergeben (keine Lücken bei Abbruch).
- Eventabsagen gehen nur an Käufer mit bezahlter oder offener Bestellung.
- Cron-Ereignisse werden auch nach Plugin-Updates eingeplant; Admin zeigt Startzeiten in der Event-Zeitzone; Seat-Map- und Übersetzungs-Endpunkte korrigiert.

### WooCommerce-Adapter

- WooCommerce-Produkte werden über eine Auswahlliste mit einer Ticketkategorie verknüpft; die Kategorie wird zusätzlich an der Bestellposition gespeichert.
- Warenkorb und Kasse (Classic und Blocks/Store API) prüfen Verkaufsstatus, Verkaufsfenster, Höchstmenge und Restkontingent.
- Beim Anlegen der Woo-Bestellung wird das Kontingent vor der Zahlung reserviert (mindestens so lange wie „Lagerbestand halten“ in WooCommerce). Ist kein Kontingent mehr frei, bricht der Checkout vor der Zahlung ab.
- Nach Zahlung wird genau ein Ticket je Einheit ausgestellt, auch bei wiederholten Status-Hooks. Spät eintreffende Zahlungen prüfen das Kontingent erneut; ohne freien Platz wird die Bestellung als `needs_review` markiert und der Admin benachrichtigt, statt zu überbuchen.
- „In Wartestellung“ verlängert die Reservierung, „Fehlgeschlagen“/„Storniert“ gibt sie frei und widerruft gültige Tickets, Teil- und Vollerstattungen widerrufen die erstatteten Tickets (eingecheckte Tickets bleiben als Historie erhalten).
- Tickets werden auf der Danke-Seite, in „Mein Konto“ und in einer Meta-Box der Bestellung angezeigt; Bestellaktionen „Tickets erneut senden“ und „Tickets erneut ausstellen“.
- Behoben: Kostenpflichtige Bestellungen schlugen immer fehl, weil `$wpdb->last_error` mit `0` statt mit einem leeren String verglichen wurde.
- Behoben: Syntaxfehler in `src/Campaigns.php`, der jeden Checkout abbrechen ließ.
- Ticketausgabe läuft unter einer Zeilensperre der Bestellung, damit parallele Zahlungsbestätigungen keine doppelten Tickets erzeugen.
- Integrationstests gegen MariaDB (inkl. 20 paralleler Käufe auf 5 Plätze) und eigener CI-Job.
- Behoben: Ticketkategorien mit Pflicht-Teilnehmerfeldern ließen sich über WooCommerce nicht kaufen („Bitte „Firma“ für jedes Ticket ausfüllen.“ ohne Eingabefeld). Klassischer Checkout und Checkout-Block fragen die Felder jetzt je Ticket ab und geben sie an die Bestellung weiter.
- Saalplan-Kategorien: der Warenkorb prüft freie passende Plätze (WooCommerce vergibt die besten freien Plätze automatisch).
- Behoben: WooCommerce-Erstattungen werden über das Erstattungsjournal gebucht (Erstattungszeile, erstatteter Betrag, Gutschrift), sobald der Kern `Refunds::record_external()` bereitstellt; vorher wurden nur Tickets widerrufen.
- Behoben: Bei Nachnahme wurden Tickets schon bei „In Bearbeitung“ ausgestellt; jetzt erst bei „Abgeschlossen“ (Filter `wpcap_woocommerce_issue_on_completed_gateways`).
- Behoben: Die Store API (Warenkorb-Block) nahm Mengen über dem Kontingent an; Mengenauswahl und `update-item` sind jetzt begrenzt.

## 0.3.0 – Plattformgrundlagen

- Kampagnencodes mit Prozent- und Festbetrag-Rabatt, Event-/Ticketbereich, Priorität und atomarem globalem Nutzungslimit.
- Wartelistenreihenfolge mit befristeter Angebotsannahme; das Angebot reserviert keine Ticketkapazität.
- Rollenbeschränkter Umsatz-/Einlassbericht, eventgebundene Entwickler-Schlüssel mit Read-only-Scopes und Übersetzungen für Eventinhalte.
- Datenmodell für Länder-/Steuerprofile (Entwurfsstatus), Integrationskonfiguration und generische Hintergrundjobs.
- Multisite-Installation legt Tabellen und Cron-Ereignisse getrennt je Site an.
- Betriebs-, Sicherheits-, Barrierefreiheits- und Erweiterungsdokumentation sowie zusätzliche Indizes und öffentliche Fokuszustände.

Diese Version ist ein Entwicklungsstand. Externe Prüfungen und eine Freigabe für produktive Steuer-, Sicherheits- oder Barrierefreiheitsanforderungen stehen aus.
