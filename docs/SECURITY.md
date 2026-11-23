# Sicherheitsmodell und Meldungen
## Schutzgrenzen

- Ticket-, Gastbestellungs- und API-Schlüssel werden als zufällige Geheimnisse ausgegeben; in der Datenbank werden nur Hashes gespeichert. Gastlinks sind Inhaberzugänge und dürfen nicht weitergegeben werden.
- WordPress REST-Schreibzugriffe prüfen Berechtigungen und Eventbereiche. Entwickler-API-Schlüssel sind auf ein Event und lesende Scopes beschränkt, haben ein Ratenlimit und können ablaufen oder widerrufen werden (siehe [Entwickler-API-Schlüssel](#entwickler-api-schlüssel)).
- Öffentliche Checkout-, Sitzplatz- und Wartelistenrouten sind nicht authentifiziert. Sie benötigen Hosting-seitige Rate-Limits, TLS, Bot-Abwehr und Überwachung. Das Plugin begrenzt zusätzlich pro Client: Checkout (Filter `wpcap_checkout_ip_limit`, Standard 30 pro Stunde), Servicebuchungen (`wpcap_service_booking_ip_limit`, Standard 40 pro Stunde), Sitzplatz-Reservierungen, Warteliste, Bestellportal, Mollie-Webhooks und weitere. Die Zähler sind über alle PHP-Worker und Webserver hinweg atomar. Hinter einem Proxy oder CDN muss die Client-Adresse richtig aufgelöst werden; siehe [Ratenlimits hinter Proxys und mit mehreren Workern](#ratenlimits-hinter-proxys-und-mit-mehreren-workern).
- Limits auf fremdsteuerbaren Schlüsseln (A644): Grenzen je E-Mail-Adresse oder Telefonnummer – Werte, die jede:r in eine Anfrage schreiben kann – zählen erst nach der Prüfung und nur bei Erfolg, damit Dritte eine Adresse nicht mit Unsinn-Anfragen sperren: Warteliste (5 Anmeldungen mit Mail je Adresse, Event und Stunde, `Waitlists::EMAIL_HOURLY_LIMIT`; ungültige Anfragen und das Wiederholen einer offenen Anmeldung zählen nicht, die Client-Grenze von 20 je Event und Stunde zählt jede gültige Anfrage), Servicebuchung (20 Reservierungen je Adresse und Stunde, `ServiceBookings::EMAIL_HOURLY_LIMIT`, nur zustande gekommene; die Client-Grenze zählt jeden Versuch) und SMS-Einwilligung (höchstens 3 je Nummer und Tag zählen nur Einwilligungen **bezahlter** Bestellungen). Die Gerätekopplung zählt bereits nur Fehlversuche je Client (A460).
- Selbstbedienung im Bestellportal (A118) nutzt dieselbe Prüfung wie das Portal (Bestell-ID + Zugangstoken). Ticket-PDFs werden per POST mit Nonce abgerufen, je Bestellung und Client begrenzt und enthalten nur gültige, unbenutzte Tickets der eigenen Bestellung, die nicht an andere Personen übertragen oder zugewiesen sind. Seit Schema 3.10.17 (A586) liegt der aktuelle Ticketcode zusätzlich **verschlüsselt** in `wpcap_tickets.code_secret` (`PassCaptain\TicketCodes`: sodium-secretbox mit dem Schlüssel des Secret-Speichers – `WPCAP_SECRETS_KEY`, sonst aus den Salts; Rotation über `WPCAP_SECRETS_KEY_PREVIOUS` –, an die Ticket-ID gebunden, beim Widerruf gelöscht); geprüft wird beim Einlass weiterhin nur der SHA-256-Hash. Wiederholte PDF-Downloads (Bestellportal, Admin „Ticket-PDF herunterladen“ je Bestellung oder Ticket) verwenden daher denselben Code, statt die versendeten oder gedruckten Codes zu entwerten. Nur ein Ticket ohne gespeicherten Code (vor dem Update ausgestellt, Schlüssel geändert oder Speicherung per `wpcap_store_ticket_codes` abgeschaltet) erhält beim Portal-Download einmal einen neuen Code (der alte wird ungültig); der Admin-Download stellt nie neue Codes aus. Abwägung: Wer Datenbank **und** Schlüssel besitzt, kann gespeicherte Codes entschlüsseln; ein reiner Datenbank-Dump genügt dafür nicht. Stornierungsanfragen (Formularnachweis, Idempotenzschlüssel, Rate-Limit) landen immer als `pending_approval` in der Erstattungsfreigabe; ausgezahlt wird erst nach der Entscheidung einer berechtigten Person. Ab Einlassbeginn und solange der Code in einem Offline-Paket steckt, gibt es keine neuen Codes per Selbstbedienung (A593, siehe unten). Der Formularnachweis der Stornierungsanfrage (A459) ist kein WordPress-Nonce, sondern ein HMAC über Bestell-ID und Zugangstoken (`PortalSelfService::refund_form_sig()`): Das Portal-Skript sendet ohne `X-WP-Nonce`, die REST-API behandelt die Anfrage daher als abgemeldet – ein für angemeldete Käufer erzeugter Nonce passte nie, und auf einer lange offenen Seite lief er ab. Der Nachweis ist benutzerunabhängig, läuft nicht ab und wird mit einem neuen Bestelllink ungültig; Nonces bereits geöffneter Seiten werden weiter akzeptiert. Ticket-PDFs werden per POST mit Nonce abgerufen, je Bestellung und Client begrenzt und enthalten nur gültige, unbenutzte Tickets der eigenen Bestellung, deren aktueller Code nicht bei einer anderen Person liegt (A581, `TicketCodes::held_elsewhere()`): übertragen, an eine andere Adresse zugewiesen oder per „erneut senden“/„neu ausstellen“ an die Teilnehmer-E-Mail geschickt (`wpcap_tickets.code_with_attendee`, Schema 3.10.22). Eine beim Checkout eingetragene Teilnehmer-E-Mail allein zählt nicht – die Ticketmail ging nur an die Käuferin; ebenso gehen Erinnerungen an die Adresse, die den Code hat. Beim Update werden bestehende Tickets mit abweichender Teilnehmer-E-Mail nur dann bei der Käuferin belassen, wenn das Audit-Log seit ihrer Ausstellung keine Zustellung an andere zeigt.
- Bestelllink von der Ticketseite (A118, A631): „Neuen Bestelllink per E-Mail senden“ kann jede Person auslösen, die den Ticketcode kennt (z. B. ein abfotografierter QR-Code). Der neue Link geht nur an die Bestelladresse und ist zunächst **vorgemerkt** (`wpcap_orders.access_pending_hash`, 7 Tage gültig, Schema 3.10.25): Der bisherige Link bleibt gültig, bis der neue zum ersten Mal geöffnet wird; erst dann ersetzt er ihn (`Orders::access_matches()`, Audit `order_link_activated`). So kann niemand den Käufer per Ticketcode aus seinem Bestellportal aussperren. Alle Prüfungen des Bestellzugangs (Portal, REST, Rechnungen, Umtausch, Teilnehmerzuweisung, Übertragung) laufen über `Orders::access_matches()`. Andere Neuausstellungen (Rückkehrseite, Kasse, Warteliste, WooCommerce) beenden den alten Link weiterhin sofort und verwerfen einen vorgemerkten; die Anonymisierung verwirft ihn ebenfalls.
- Selbstbedienung im Bestellportal (A118) nutzt dieselbe Prüfung wie das Portal (Bestell-ID + Zugangstoken). Ticket-PDFs werden per POST mit Nonce abgerufen, je Bestellung und Client begrenzt und enthalten nur gültige, unbenutzte Tickets der eigenen Bestellung, die nicht an andere Personen übertragen oder zugewiesen sind. Ein PDF enthält höchstens 50 Tickets (A627, `wpcap_portal_pdf_max_tickets`); größere Bestellungen laden in Teilen. Seit Schema 3.10.17 (A586) liegt der aktuelle Ticketcode zusätzlich **verschlüsselt** in `wpcap_tickets.code_secret` (`PassCaptain\TicketCodes`: sodium-secretbox mit dem Schlüssel des Secret-Speichers – `WPCAP_SECRETS_KEY`, sonst aus den Salts; Rotation über `WPCAP_SECRETS_KEY_PREVIOUS` –, an die Ticket-ID gebunden, beim Widerruf gelöscht); geprüft wird beim Einlass weiterhin nur der SHA-256-Hash. Wiederholte PDF-Downloads (Bestellportal, Admin „Ticket-PDF herunterladen“ je Bestellung oder Ticket) verwenden daher denselben Code, statt die versendeten oder gedruckten Codes zu entwerten. Nur ein Ticket ohne gespeicherten Code (vor dem Update ausgestellt, Schlüssel geändert oder Speicherung per `wpcap_store_ticket_codes` abgeschaltet) erhält beim Portal-Download einmal einen neuen Code (der alte wird ungültig); der Admin-Download stellt nie neue Codes aus. Abwägung: Wer Datenbank **und** Schlüssel besitzt, kann gespeicherte Codes entschlüsseln; ein reiner Datenbank-Dump genügt dafür nicht. Stornierungsanfragen (Formularnachweis, Idempotenzschlüssel, Rate-Limit) landen immer als `pending_approval` in der Erstattungsfreigabe; ausgezahlt wird erst nach der Entscheidung einer berechtigten Person. Ab Einlassbeginn und solange der Code in einem Offline-Paket steckt, gibt es keine neuen Codes per Selbstbedienung (A593, siehe unten). Der Formularnachweis der Stornierungsanfrage (A459) ist kein WordPress-Nonce, sondern ein HMAC über Bestell-ID und Zugangstoken (`PortalSelfService::refund_form_sig()`): Das Portal-Skript sendet ohne `X-WP-Nonce`, die REST-API behandelt die Anfrage daher als abgemeldet – ein für angemeldete Käufer erzeugter Nonce passte nie, und auf einer lange offenen Seite lief er ab. Der Nachweis ist benutzerunabhängig, läuft nicht ab und wird mit einem neuen Bestelllink ungültig; Nonces bereits geöffneter Seiten werden weiter akzeptiert. Ticket-PDFs werden per POST mit Nonce abgerufen, je Bestellung und Client begrenzt und enthalten nur gültige, unbenutzte Tickets der eigenen Bestellung, deren aktueller Code nicht bei einer anderen Person liegt (A581, `TicketCodes::held_elsewhere()`): übertragen, an eine andere Adresse zugewiesen oder per „erneut senden“/„neu ausstellen“ an die Teilnehmer-E-Mail geschickt (`wpcap_tickets.code_with_attendee`, Schema 3.10.22). Eine beim Checkout eingetragene Teilnehmer-E-Mail allein zählt nicht – die Ticketmail ging nur an die Käuferin; ebenso gehen Erinnerungen an die Adresse, die den Code hat. Beim Update werden bestehende Tickets mit abweichender Teilnehmer-E-Mail nur dann bei der Käuferin belassen, wenn das Audit-Log seit ihrer Ausstellung keine Zustellung an andere zeigt.
- Personalisierung (A569, A570): Teilnehmername und -angaben ändern Käufer:innen im Bestellportal und – auch nach einer Übertragung, wenn der Bestelllink das Ticket nicht mehr ändern darf – die aktuelle Inhaberin über den Ticketlink (Ticketseite, POST mit Nonce, Frist `attendee_edit_hours` vor Beginn, nur gültige Tickets bezahlter Bestellungen; Code und E-Mail-Adresse bleiben). Ein Personenwechsel ist begrenzt (`wpcap_max_holder_changes`, Standard 1; eine Übertragung zählt mit) – gleich, ob über eine andere E-Mail-Adresse (neuer Code) oder nur über einen anderen Namen; kleine Korrekturen (Schreibweise, Wortreihenfolge, bis zu zwei Tippfehler) zählen nicht als Personenwechsel, aber jede Namensänderung zählt gegen `wpcap_max_attendee_name_changes` (Standard 3). Beide Zähler liegen am Ticket (`wpcap_tickets.holder_changes`, `name_changes`, Schema 3.10.22) und überstehen eine Bereinigung des Audit-Logs; Korrekturen durch das Team im Ticket-Admin sind nicht begrenzt.
- Überweisung (`manual`) hält Tickets bis zu `manual_hold_hours` ohne Zahlung. Begrenzt wird das durch höchstens 2 offene Überweisungsbestellungen je E-Mail-Adresse, 5 je Client in der Reservierungszeit und – unabhängig davon, wie viele Adressen oder IPs jemand nutzt – eine **Obergrenze der unbezahlt gehaltenen Menge** (A634): Offene Überweisungsbestellungen halten zusammen höchstens `manual_hold_max_percent` (Standard 50 %, mindestens 10 Tickets, Filter `wpcap_manual_hold_min_units`) jeder Ticketart und jedes Termins mit Kapazität. Neue Überweisungsbestellungen des öffentlichen Checkouts werden dagegen geprüft (vertrauenswürdige Kanäle wie Admin-Kontingente, Kasse oder Partner nicht), in der Bestell-Transaktion unter den Sperren von Ticketart und Termin, also atomar auch bei parallelen Anfragen; darüber lehnt der Checkout mit `reason` `manual_hold_limit` ab und Käufer:innen können sofort zahlende Zahlarten nutzen. Rabattcodes mit Nutzungslimit werden von solchen Bestellungen ebenfalls belegt, bis sie bezahlt werden oder ablaufen.
- Öffentlicher Checkout (A639, A642): Ein vom Client gewählter Idempotenzschlüssel muss mindestens 16 Zeichen haben (das Formular sendet eine UUID); ein Wiederholversuch mit demselben Schlüssel, aber anderer Zahlart wird abgelehnt (`wpcap_checkout_provider_changed`), und Aufräum-Stornos eines Versuchs (Gutschein abgelehnt, Zahlung nicht startbar) treffen nur noch unbezahlte Bestellungen. Scheitert der Start der Zahlung beim Anbieter, erhalten Käufer:innen nur `wpcap_payment_start_failed` mit einer allgemeinen Meldung; Fehlertext und Fehlercode des Anbieters stehen bereinigt im Audit-Log (`payment_start_failed`) und im PHP-Fehlerprotokoll. Dasselbe gilt für Wartelistenangebote und Umtausch-Aufpreise im Bestellportal.
- Offline-Scanner speichern Codes lokal und können während einer Netzunterbrechung nicht garantieren, dass ein Ticket an einem anderen Gerät unbenutzt geblieben ist.
- Jede ausgelieferte PHP-Datei des Plugins (ohne `vendor/`) beendet sich bei direktem Aufruf: `ABSPATH`-Prüfung vor der ersten Anweisung (`uninstall.php`: `WP_UNINSTALL_PLUGIN`). `tests/consistency.php` prüft das in der CI für alle Dateien des Release-ZIPs (A367).
- Integrationsadapter werden standardmäßig nicht aktiviert. Externe Zugangsdaten gehören in geschützte WordPress-Konfiguration bzw. Secret-Storage, niemals in Quellcode oder Logs.

- Ticket-, Gastbestellungs- und API-Schlüssel werden als zufällige Geheimnisse ausgegeben; in der Datenbank werden nur Hashes gespeichert. Gastlinks sind Inhaberzugänge und dürfen nicht weitergegeben werden.
- WordPress REST-Schreibzugriffe prüfen Berechtigungen und Eventbereiche – auch für Objekt-IDs im Body (Ticketart, Ressource, Pass, Buchung, Key). Entwickler-API-Schlüssel sind auf ein Event sowie `events.read` und `reports.read` (plus Add-on-Scopes) beschränkt und können ablaufen oder widerrufen werden. Ein Key gilt nur, solange die Inhaberin die passende Capability über ihre Rolle **und** als Eventpersonal hat; beim Löschen/Entfernen der Person werden ihre Keys widerrufen und ihre Zuweisungen entfernt, beim Rollenwechsel die nicht mehr nutzbaren Keys widerrufen. Ratenlimits über 6.000 Anfragen/Minute je Key (bis 100.000) kann nur ein Administrator setzen.
- Die Checkout-Antwort enthält weder die fortlaufende `order_id` noch die `public_id` (Teil des Portal-Links); der Browser bekommt nur die kurze Bestellnummer (`reference`). Zugangslink und volle ID gehen nur per E-Mail.
- Öffentliche Checkout-, Sitzplatz- und Wartelistenrouten sind nicht authentifiziert. Sie benötigen Hosting-seitige Rate-Limits, TLS, Bot-Abwehr und Überwachung (siehe „Rate-Limits“).
- Offline-Scanner speichern Codes lokal und können während einer Netzunterbrechung nicht garantieren, dass ein Ticket an einem anderen Gerät unbenutzt geblieben ist.
- Jede ausgelieferte PHP-Datei des Plugins (ohne `vendor/`) beendet sich bei direktem Aufruf: `ABSPATH`-Prüfung vor der ersten Anweisung (`uninstall.php`: `WP_UNINSTALL_PLUGIN`). `tests/consistency.php` prüft das in der CI für alle Dateien des Release-ZIPs (A367).
- Integrationsadapter werden standardmäßig nicht aktiviert. Externe Zugangsdaten gehören in geschützte WordPress-Konfiguration bzw. Secret-Storage, niemals in Quellcode oder Logs.

## Zugangsdaten (Secrets-Vault)

Betroffen sind alle Anbieter-Geheimnisse: Stripe Secret Key und Webhook-Signing-Secret, PayPal Client-Secret, Mollie API-Key, Twilio Auth Token, Auth-Header des generischen SMS-Anbieters, Apple-Wallet-Zertifikat/Schlüssel/Passwort/WWDR, Google-Wallet-Servicekonto sowie die Signing-Secrets ausgehender Webhooks (`Settings::SECRET_KEYS`, erweiterbar über den Filter `wpcap_secret_settings`).

**Reihenfolge der Quellen** (unverändert): Konstante `WPCAP_<KEY>` in `wp-config.php` → Umgebungsvariable `WPCAP_<KEY>` → in der Datenbank gespeicherter Wert. Konstanten bleiben die empfohlene Variante; sie werden nie in der Datenbank gespeichert.

**Verschlüsselung at rest.** In `wpcap_settings` (und in `wpcap_webhook_subscriptions.secret`) gespeicherte Werte werden mit libsodium (`sodium_crypto_secretbox`, XSalsa20-Poly1305) verschlüsselt und als `pcv1:<base64(nonce|ciphertext)>` abgelegt. Pro Feld wird per HKDF ein eigener Teilschlüssel abgeleitet; ein in ein anderes Feld kopierter Chiffretext lässt sich nicht entschlüsseln, manipulierte Werte werden abgelehnt. Nur `Settings::secret()` liefert den Klartext; `Settings::get()`/`all()` sehen nur den Chiffretext.

**Schlüssel** (liegt nie in der Datenbank):

1. Konstante `PASS_CAPTAIN_SECRET_KEY` in `wp-config.php` – empfohlen. Format: 64 Hex-Zeichen (32 Byte), Base64 von 32 Byte oder eine lange Passphrase (wird per HKDF abgeleitet). Erzeugen z. B. mit `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`.
2. Umgebungsvariable `PASS_CAPTAIN_SECRET_KEY`.
3. **Fallback mit Warnung:** aus den wp-config-Salts `AUTH_KEY` und `SECURE_AUTH_KEY` abgeleitet (HKDF-SHA256). Die Einstellungsseite weist darauf hin. Werden die Salts erneuert (z. B. nach einem Sicherheitsvorfall), sind die gespeicherten Zugangsdaten nicht mehr lesbar und müssen neu eingegeben werden.

Wer Datenbank und `wp-config.php` gemeinsam erbeutet, kann die Werte entschlüsseln – der Vault schützt gegen Datenbank-Dumps, Backups, SQL-Injection-Lesezugriffe und Admin-Exporte, nicht gegen eine vollständige Server-Kompromittierung.

**Migration.** Beim Schema-Upgrade und bei Admin-Aufrufen (bis erledigt, Option `wpcap_vault_version`) werden noch im Klartext gespeicherte Werte einmalig verschlüsselt (idempotent, `Security\Vault::migrate()`). Eine Webhook-Spalte wird erst nach der Verbreiterung auf `varchar(255)` umgestellt, damit nie ein Secret abgeschnitten wird.

**Schlüsselwechsel.** Alten Schlüssel in `PASS_CAPTAIN_SECRET_KEY_PREVIOUS` hinterlegen, neuen in `PASS_CAPTAIN_SECRET_KEY` setzen, eine Admin-Seite aufrufen (Migration verschlüsselt neu), danach die alte Konstante entfernen. Beim Umstieg vom Salt-Fallback auf eine eigene Konstante werden die alten Werte automatisch gelesen und neu verschlüsselt.

**Schlüsselverlust.** Ein nicht entschlüsselbarer Wert gilt als „nicht gesetzt“ (der Anbieter erscheint als nicht konfiguriert, es wird nie mit Datenmüll gearbeitet). Ein Admin-Hinweis nennt die betroffenen Einstellungen und bittet um erneute Eingabe; das Feld zeigt „kann nicht entschlüsselt werden“.

**Auszahlungsdaten der Veranstalter.** `wpcap_partners.payout_details` (IBAN, BIC, Kontoinhaber) wird mit demselben Vault verschlüsselt, Kontext `partner_payout:<partner_id>` (an die Zeile gebunden: in eine andere Zeile kopiert, lässt sich der Wert nicht entschlüsseln). Der Partner-Schnappschuss einer abgeschlossenen Abrechnung enthält die Angaben ebenfalls nur verschlüsselt (Kontext `partner_settlement_payout:<settlement_id>`). Bestehende Klartextwerte (Tabelle und Schnappschüsse) verschlüsselt `Vault::migrate()` einmalig beim Update (Vault-Version 2, Schema 3.10.0; idempotent, auch beim Schlüsselwechsel). Anzeige im Admin nur gekürzt („•••• 3000“, letzte 4 Zeichen der IBAN); ein leeres Feld behält den Wert. **Entscheidung Export:** vollständig nur im CSV-Export der Veranstalter-Abrechnung und nur für Finanzen/Admins (`Settlements::can_manage()`), weil der Betreiber damit die Überweisung ausführt; verknüpfte Veranstalter-Benutzer erhalten dort die gekürzte Form. Druckbeleg, Partner-REST-API, Berichte und sonstige Exporte enthalten die Angaben nie. Bei Schlüsselverlust zeigt das Formular „kann nicht entschlüsselt werden“ und bittet um Neueingabe.

**Fiskal-Zugangsdaten.** Zugangsdaten der Fiskalanbieter je Rechtsträger und Anbieter (Option `wpcap_fiscal_credential_vault`) liegen ebenfalls im Vault, Kontext `fiscal_credentials:<seller_id>:<provider_id>` (in einen anderen Verkäufer/Anbieter kopiert, nicht entschlüsselbar). Ohne libsodium oder Schlüssel wird das Speichern abgelehnt – nie Klartext. Einträge, die nur mit dem vorherigen Schlüssel lesbar sind, werden bei Verwendung und von `Vault::migrate()` neu verschlüsselt (Vault-Version 3, Schema 3.11.0); Einträge des früheren Entwurfsformats (AES-GCM aus den Salts) werden einmalig übernommen, nicht übernehmbare gelöscht (Log `fiscal_credentials_legacy_dropped`). Werte werden nie angezeigt, nie per REST ausgegeben und nie ins Audit-Log geschrieben (nur Feldnamen); entschlüsselte Werte registriert `Redact::remember()` für den Rest der Anfrage. Zugriffstoken der Anbieter (fiskaly) werden nur verschlüsselt zwischengespeichert (Kontext `fiscal_token:<cache key>`).

**Anzeige.** Admin-Seiten geben Secrets nie aus: angezeigt wird nur „gespeichert ••••1234“ (die letzten vier Zeichen ab 16 Zeichen Länge, sonst nur „••••“), ein leeres Ersetzen-Feld und „Entfernen“. Webhook-Signing-Secrets werden nach dem Anlegen bzw. Erneuern genau einmal angezeigt (bis dahin verschlüsselt zwischengespeichert), danach nur maskiert.

## Redaktion (Logs, Audit, Fehler)

`Security\Redact` ist die zentrale Stelle:

- `Audit::record()` speichert Details nur redigiert: Werte unter Schlüsseln wie `secret`, `token`, `password`, `api_key`, `authorization`, `private_key`, `signature`, `iban` werden entfernt, E-Mail-, Telefon-, IP-, Namens- und Anschriftsfelder (`email`, `phone`, `mobile`, `recipient`, `ip`, `ip_address`, `attendee_name`, `contact_name`, `street`, `address`, `postcode` …) maskiert (`j***@example.com`; bereits gekürzte IP-Adressen wie `198.51.100.0` bleiben lesbar).
- Freitexte (Anbieterantworten, DB-Fehler, WP_Error-Meldungen) werden von konfigurierten Secrets (exakter Wert), Stripe-/Mollie-/Twilio-/PayPal-Tokens, JWTs, Partner- und Entwickler-Keys, `whsec_…`, PEM-Private-Keys, 64-stelligen Ticket-/Zugangscodes (gekürzt), Token-Query-Parametern, E-Mail-Adressen, IBANs, IPv4-/IPv6-Adressen und Telefonnummern (mit Präfix + / 00 / 0, 8–15 Ziffern; Datumsangaben, Uhrzeiten und IDs bleiben) befreit.
- Fehlermeldungen von Stripe, PayPal, Mollie und Twilio werden vor der Weitergabe (REST-Antwort, Bestellnotiz, Audit) redigiert.
- Das Plugin schreibt nur über `Redact::log()` ins PHP-Error-Log (DB-Fehler können Zeilenwerte zitieren). Filter `wpcap_error_log` (false = nicht schreiben).

## Ausgehende Verbindungen (SSRF)

Für vom Admin konfigurierbare Ziele – Webhooks, HTTP/JSON-Connectors, generischer SMS-Anbieter, Fiskaldienste – gilt `Security\OutboundHttp`:

- nur `https`; keine Zugangsdaten in der URL; nicht-kanonische IP-Schreibweisen (`0x7f.1`, `2130706433`) werden abgelehnt;
- der Host wird aufgelöst, **jede** Adresse muss öffentlich sein: Loopback, RFC 1918, CGNAT (100.64/10), Link-Local inkl. Cloud-Metadaten (169.254.169.254), ULA (fc00::/7), fe80::/10, Multicast, reservierte und Dokumentationsnetze, IPv4-mapped und NAT64-IPv6 sowie `localhost`, `*.local`, `*.internal` sind gesperrt. Die Adressprüfung ist dieselbe wie beim zentralen Guard `PassCaptain\OutboundHttp` (Liste unten, u. a. 168.63.129.16, 2001::/23, 64:ff9b:1::/48 und `::ffff:0:a.b.c.d`; N03);
- die Prüfung läuft beim Speichern **und** vor jedem Versand (DNS-Rebinding nach dem Anlegen wird erkannt); zusätzlich `wp_safe_remote_*`;
- keine Weiterleitungen (3xx = Fehlversuch), Timeout max. 15 s, Antwortgröße begrenzt (Webhooks/SMS 64 KB, Fiskal 512 KB), optionale JSON-Prüfung;
- Fehlermeldungen enthalten weder URL-Parameter noch Header oder Bodies.
- Lokale Fiskaldienste (z. B. efsta EFR unter `http://127.0.0.1:5618`) sind nur erreichbar, wenn „Lokalen Fiskaldienst erlauben“ (`fiscal_allow_local_service`, Filter `wpcap_fiscal_allow_local_service`) aktiv ist – und auch dann nur Loopback/private Adressen, nie Klartext-HTTP ins Internet.
- APNs: das Pass-Type-Zertifikat wird nur `api.push.apple.com`/`api.sandbox.push.apple.com` vorgelegt (nur HTTPS, keine Redirects). Google Wallet: der Token-Endpunkt ist fest (`https://oauth2.googleapis.com/token`, Filter `wpcap_wallet_google_token_uris`); ein abweichendes `token_uri` im Servicekonto-JSON wird ignoriert.
- Zahlungsanbieter (Stripe, PayPal, Mollie, Twilio) verwenden feste Anbieter-Hosts.

Restrisiko: Zwischen Prüfung und Verbindungsaufbau löst WordPress den Namen erneut auf; ein Angreifer mit TTL-0-DNS könnte theoretisch umschalten. Für Hochrisiko-Umgebungen ausgehenden Traffic zusätzlich per Egress-Firewall/Proxy beschränken.

## Signierte QR-Codes (PC1)

Tickets tragen standardmäßig einen zufälligen 256-Bit-Code (gespeichert wird nur `sha256`). Optional
(Einstellung „Signierte QR-Codes ausgeben“, `signed_qr`, **Standard aus**) enthalten neu gerenderte QR-Codes
(Ticketseite, PDF, Druckvorlagen, Apple/Google Wallet) stattdessen eine signierte Angabe:

```
PC1.<base64url(JSON {t, o, v, k, c})>.<base64url(Ed25519-Signatur)>
```

- `t` öffentliche Ticket-ID, `o` Termin-ID, `v` Gültigkeitszeitraum `[nicht vor, gültig bis]` (Unix-Sekunden,
  Standard: ein Jahr vor Beginn bis einen Tag nach dem letzten gültigen Zeitpunkt, Filter
  `wpcap_signed_qr_window`), `k` Schlüssel-ID, `c` Code-Version (erste 16 Hex-Zeichen des gespeicherten
  Code-Hashes). **Keine personenbezogenen Daten**, kein Ticketcode. Signiert werden die ASCII-Bytes
  `PC1.<Claims>` (Domänentrennung über das Präfix).
- Schlüssel: Ed25519 (libsodium), Option `wpcap_qr_keys`; der geheime Schlüssel liegt im Vault (Kontext
  `qr_signing_key`) und verlässt den Server nie. Schlüssel-ID = erste 16 Hex-Zeichen von `sha256(public)`.
  Öffentliche Schlüssel erhalten gekoppelte Geräte über `GET /app/offline/keys` (`qr_keys`) und die
  Kopplungsantwort.
- Rotation (Offline-Scanner-Seite, `manage_options`, Audit `qr_key_rotated`): der bisherige Schlüssel bleibt
  bis zum Ablauf der damit signierten QR-Codes gültig; mit „sofort sperren“ (Kompromittierung) wird er
  entfernt und alle damit signierten QR-Codes werden abgewiesen (Tickets dann neu versenden).
- Server: `Checkin::lookup()` prüft Format, Signatur und Schlüssel, lädt das Ticket über die öffentliche ID,
  verlangt passende Code-Version und Termin (sonst `wpcap_qr_superseded`, Ergebnis `revoked` – eine
  Neuausstellung macht alte QR-Codes ungültig wie den alten Code) und beim Einlass den Gültigkeitszeitraum
  (`wpcap_qr_expired`). Dieser wird gegen die aktuellen Termindaten erweitert:
  `[min(signierter Beginn, aktueller Beginn), max(signiertes Ende, aktuelles Ende)]`, das aktuelle Fenster
  nach `SignedQr::window()` (inkl. Filter). Nach einer Terminverschiebung bleibt ein bereits versendeter QR-Code
  daher für den neuen Termin gültig; die Signatur belegt Ticket und Termin ohnehin. Die Offline-PWA erweitert
  das Fenster ebenso mit den Termindaten aus dem signierten Prüfpaket. Danach gelten dieselben Prüfungen wie für klassische Codes (Status, Widerruf, Absage,
  Einlassregeln, Idempotenz). Eine gültige Signatur allein lässt nie ein. Ungültige PC1-Codes werden nie an
  Scanner-Adapter von Add-ons weitergereicht.
- Interoperabilität: Alle Pass-Captain-Scanner (Web-Scanner, Offline-PWA, App-API, Offline-Sync, Check-in-Dienst
  für fremde Scanner) nehmen **beide** Formate an – unabhängig von der Einstellung, damit bereits versendete
  Tickets nach einem Umschalten gültig bleiben. Fremde Einlasssysteme, die den 64-stelligen Code lesen,
  funktionieren nur mit dem klassischen Format; deshalb ist die Signatur Opt-in. Der Ticketcode bleibt als
  Text auf Ticketseite/PDF für die manuelle Eingabe.
- Restrisiken: Ein signierter QR-Code ist wie der klassische Code ein Inhaberberechtigungsnachweis (Kopien
  sind bis zum ersten Einlass gleichwertig); die Signatur verhindert Fälschungen und erlaubt die
  Offline-Vorprüfung, ersetzt aber nicht die Statusprüfung am Server bzw. im signierten Prüfpaket.

## Private Seiten (Geheimlinks und Personal)

Seiten, die über einen Geheimlink erreichbar sind oder Personal vorbehalten sind – Bestellportal, Ticketseite, Rechnungsansicht/-PDF (Käufer und Admin-Druck), Service-Portal, Wartelisten-Angebot und -Annahme, Einwilligungsseite für Teilnehmende und jede Seite mit dem Shortcode `[wp_pass_captain_scanner]` –, senden über `Security\PrivatePage::send()`:

- `DONOTCACHEPAGE` und `nocache_headers()` sowie `Cache-Control: no-store, …, private` – Seiten-Caches (WP Super Cache, W3TC, LiteSpeed, Hosting-Caches) legen keine Kopie an, die ein zweiter Besucher bekäme;
- `X-Frame-Options: DENY` und `Content-Security-Policy: frame-ancestors 'none'` – kein Einbetten in fremde Seiten (Clickjacking von „Annehmen/Übertragen/Widerrufen“);
- `X-Robots-Tag: noindex, nofollow, noarchive`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`.

**Strikte Content-Security-Policy (FE-8):** Seiten, die das Plugin vollständig selbst ausgibt (kein Theme, kein `wp_head()`), senden über `PrivatePage::send( true )` statt nur `frame-ancestors` die volle Policy

```
default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;
object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'
```

Das gilt für die Ticketseite (`?wpcap_ticket=`), die Rechnungsansicht (Käufer-Link und Admin-Druck) und die Einwilligungsseite für Teilnehmende. Liegen Plugin-Dateien bzw. Uploads auf einem anderen Host (CDN-Offloading), wird dessen Origin automatisch zu `script-src`/`img-src` ergänzt; weitere Quellen (z. B. ein Markenlogo von einem fremden Host) über den Filter `wpcap_strict_csp`. `style-src 'unsafe-inline'` bleibt bewusst: Die Seiten bringen einen kleinen `<style>`-Block mit (Druckansicht ohne zusätzliche Datei), Markenlogos haben `style`-Attribute; Inline-CSS führt keinen Code aus, alle Ausgaben sind escaped. Nicht strikt (nur `frame-ancestors`) bleiben Seiten mit Inline-Konfiguration oder Theme-Anteil: Bestellportal (`window.wpcapPortal` inline), Service-Portal und Wartelisten-Seiten (eigene Pakete) sowie Theme-Seiten mit Scanner-Shortcode – dort bestimmen Theme und andere Plugins die Skripte.

Damit das möglich ist, enthält das Plugin **keine Inline-Event-Handler** (`onclick=`, `onsubmit=`, `onchange=`, `onload=` …) mehr: Druck-Buttons tragen `data-wpcap-print` (Skript `assets/print.js`, als versionierte Datei eingebunden; Kassenbelege drucken über `<body data-wpcap-autoprint>`), Bestätigungsdialoge im Admin `data-wpcap-confirm="…"` (Skript `assets/admin-confirm.js`, nur auf den Plugin-Seiten geladen; weiterhin natives `window.confirm()`), Auswahllisten mit Sofort-Absenden `data-wpcap-autosubmit`. Die Offline-Scanner-App setzt ihre strikte CSP selbst (`<meta http-equiv>` in `index.html`).

Die Offline-Scanner-App (`assets/offline-scanner/`) ist eine statische Datei, die der Webserver direkt ausliefert; PHP kann dort keine Header setzen. Empfehlung: `X-Frame-Options: DENY`, `X-Robots-Tag: noindex` und `Cache-Control: no-cache` für dieses Verzeichnis in der Server-Konfiguration.

Request-Parameter, die als Array ankommen (`?wpcap_access[]=x`, JSON), werden als leer behandelt (`Security\Input::text()/hex()`), statt „Array to string conversion“ auszulösen.

### Zugangstokens nicht in URLs (SEC-7 / PRIV-14)

**Mail-Links (Bestellportal, Ticketseite, Rechnung, Rechnungs-PDF):** Beim ersten Aufruf mit `?wpcap_access=…` bzw. `?wpcap_ticket=…` prüft die Seite das Token und tauscht es gegen ein kurzlebiges Cookie (`Security\AccessCookie`):

- Antwort `303 See Other` auf dieselbe Adresse **ohne Token** (`?wpcap_order=<ID>`, `?wpcap_invoice=<ID>`, `?wpcap_invoice_pdf=<ID>`, Ticket: `?wpcap_tref=<Referenz>`) plus Merker `wpcap_ck=1`; auch diese Antwort sendet die Private-Page-Header (kein Cache).
- Cookie `wpcap_ax_<Kennung>` je Bestellung bzw. Ticket: `HttpOnly`, `SameSite=Lax`, `Secure` auf HTTPS-Seiten, Pfad = Pfad der Website (`home_url('/')`), Laufzeit 2 Stunden. Der Wert ist das Token, versiegelt mit dem Secrets-Vault (XSalsa20-Poly1305, eigener Kontext, gebunden an Bestellung/Ticket und Ablauf) – nicht fälschbar und nicht auf eine andere Bestellung übertragbar. Serverseitig liegt weiterhin nur der SHA-256-Hash des Tokens.
- Die Ticket-Referenz in der URL ist ein verschlüsselter Hash (HMAC mit dem Site-Schlüssel), weder das Token noch dessen einfacher Hash.
- Folgeanfragen (Neuladen, Rechnungslink im Portal) werden über das Cookie autorisiert; das Portal verlinkt die Rechnung dann ohne Token.
- **Cookies blockiert:** Kommt nach dem Redirect kein Cookie zurück, merkt sich die Seite das für diese Bestellung/dieses Ticket einen Tag lang (Transient ohne Geheimnis) und bittet, den Mail-Link erneut zu öffnen. Dieser zweite Aufruf zeigt die Seite direkt mit dem Token in der URL – keine Endlosschleife, die Token-URL funktioniert weiter wie bisher. Ohne libsodium/Schlüssel (Vault kann nicht versiegeln) entfällt der Tausch ebenfalls.
- Nur `GET`/`HEAD` werden umgeleitet; Formular-POSTs (Einwilligungen, Sprache) behalten ihren Inhalt.
- Bekannte Grenze: Der erste Aufruf mit Token steht weiterhin im Zugriffslog des Webservers und ggf. in der Mail. Link-Scanner von Mailprogrammen, die keine Cookies speichern, können den Merker „Cookies blockiert“ auslösen; dann gilt für diesen Link das frühere Verhalten (Token-URL).

**REST (Bestellportal, Servicebuchungen, Wartelisten):** Öffentliche Routen, die ein Käufer-Token erwarten (`GET /orders/{id}`, `GET /orders/{id}/service-bookings`, `GET …/rebook-options`, `GET /service-waitlist/{id}` sowie die POST-Routen für Übertragung, Zuweisung, erneutes Senden, Rechnungsdaten, Stornieren/Umbuchen und Wartelistenaktionen), lesen das Token zuerst aus dem Header **`X-WPCAP-Access-Token`** (`Rest\Credentials`), sonst aus dem Parameter `access_token` bzw. `token`. Das eigene Frontend (Skript des Bestellportals in `OrderPortal::render()`) sendet nur noch den Header. Query-Parameter bleiben aus Kompatibilitätsgründen akzeptiert (Links in älteren Mails, bestehende Integrationen); sie landen aber in Zugriffslogs und sollten in neuen Integrationen nicht verwendet werden. Bei `POST /service-bookings/{id}/attach-order` trägt der Header das Bestell-Token, das Buchungs-Token bleibt Parameter. Der Header steht in der CORS-Freigabeliste (`rest_allowed_cors_headers`).

## CSV-Exporte

Alle CSV-Exporte laufen über `Reports\Scope::csv()`: RFC-4180-Quoting ohne Backslash-Escape (ein `\"` im Text kann die Zelle nicht beenden) und `csv_cell()`: führende `= + - @` (auch nach Leerzeichen) sowie solche Zeichen direkt nach `"`, `;` oder Tab im Text werden mit `'` entschärft; gültige JSON-Dokumente (Archivspalten) bleiben unverändert.

## Rate-Limits und Client-Adresse

Alle Plugin-Limits (Checkout pro IP und pro IP + E-Mail, Servicebuchungen, Terminwarteliste, Servicepässe, Event-Warteliste, Sitzplatz-Holds, Partner- und Entwickler-API, Kopplung, Wallet-Webservice, Portal, SMS-Anmeldung, Affiliate-Codes) laufen über die gemeinsame API `RateLimit::hit()`/`RateLimit::allow()` und zählen **atomar** (QA-V2): mit persistentem Object-Cache über `wp_cache_incr()` in der Transient-Gruppe, sonst in der Tabelle `wpcap_rate_limits` mit einem einzigen `INSERT … ON DUPLICATE KEY UPDATE` (Zeilensperre; der Zählerstand kommt per `LAST_INSERT_ID(expr)` exakt für die eigene Anfrage zurück). Parallele Anfragen – auch über mehrere PHP-Worker oder Webserver mit gemeinsamer Datenbank – lassen so nie mehr als das Limit durch; ein Integrationstest mit parallelen Prozessen prüft das. Gespeichert wird nur ein HMAC des Zählernamens (`RateLimit::hash()`), keine IP- oder E-Mail-Adresse. In der Tabelle bleibt ein abgewiesener Zähler bei Limit + 1 stehen (abgewiesene Versuche verlängern das Fenster nicht); abgelaufene Zeilen löscht der tägliche Löschlauf. Nur solange die Tabelle noch fehlt (vor dem Schema-Upgrade auf 3.16.0) gilt übergangsweise der frühere, nicht atomare Transient-Zähler. Die Limits sind **pro Site**, und hinter Proxy/CDN sind sie nur so gut wie die erkannte Client-Adresse. Empfehlung: Rate-Limiting und Bot-Schutz zusätzlich am WAF/CDN bzw. Reverse Proxy (z. B. für `/wp-json/pass-captain/v1/checkout`, `/…/app/pair`, `/…/partner/*`).

Warteliste (Event): 5 Anmeldungen je Adresse (Groß-/Kleinschreibung egal) und Event, 20 je IP und Event und 60 je IP über alle Events pro Stunde (Filter `wp_pass_captain_waitlist_ip_limit_v1`). Die Antwort verrät nicht, ob eine Adresse schon eingetragen ist, und jede Eintragungsmail enthält einen Austragen-Link.

**Double-Opt-in für Wartelisten (SEC-5)** – Einstellung *Allgemein / Reservierung → Wartelisten: Eintragung per E-Mail bestätigen lassen* (`waitlist_double_opt_in`, Standard an), gilt für Event- und Zeitfenster-Wartelisten:

- Eine Anmeldung legt einen Eintrag mit Status `unconfirmed` **ohne Position** an und sendet die Mail „Warteliste: Eintragung bestätigen“ (`waitlist_confirm` bzw. `service_waitlist_confirm`) mit Bestätigungslink. Gespeichert wird nur der SHA-256-Hash des Tokens (`confirm_token_hash`), gültig 48 Stunden (`confirm_expires_at`).
- Der Link zeigt nur ein Bestätigungsformular (Mail-Scanner bestätigen nichts); erst der POST (Nonce) setzt den Eintrag auf `waiting` und vergibt die Position am Ende der Warteschlange. Danach folgt die bisherige Eintragungsmail mit Position und Austragen-Link.
- Unbestätigte Einträge zählen nicht für Positionen, Angebote, Reservierungen und „Platz frei“-Prüfungen und werden vom täglichen Aufbewahrungslauf gelöscht, sobald der Link abgelaufen ist (`Privacy\Retention`, Schritt `unconfirmed_waitlists`).
- Eine erneute Anmeldung derselben Adresse vor der Bestätigung ersetzt den Link (die Limits oben begrenzen die Mails).
- Bestehende Einträge bleiben gültig. Ist die Einstellung aus, gilt eine Anmeldung sofort (früheres Verhalten).

**SMS-Opt-in mit Nummernbestätigung (PRIV-16):** Mit der Einstellung *SMS → Nummer per Code bestätigen* (Standard: an) gilt eine SMS-Einwilligung erst, wenn die Nummer vor der Bestellung mit einem 6-stelligen Code bestätigt wurde (`Sms\Verification`, REST `POST /sms/verify/request` und `/sms/verify/confirm`). Unbestätigte Nummern erhalten nur den Code selbst.

- Gespeichert werden nur Hashes: der Bestätigungs-Token als SHA-256, der Code als Schlüssel-HMAC (`RateLimit::hash()`), nach erfolgreicher Bestätigung wird auch dieser geleert. Code 10 Minuten gültig, höchstens 5 Fehlversuche (atomar gezählt), danach ist eine neue Anforderung nötig; erneutes Senden frühestens nach 60 Sekunden, höchstens 3 Codes je Bestätigung.
- Der Checkout verbraucht den Token genau einmal (`used_order_id`, in der Bestell-Transaktion: bricht die Bestellung ab, ist er wieder frei), nur für dieselbe Nummer und höchstens eine Stunde nach der Bestätigung. Nachweis: Spalten `verification`/`verified_at` an der Einwilligung und Audit-Eintrag `sms_consent_verified` mit Zeitpunkt und Textversion.
- **Kosten- und Missbrauchsschutz** (jeder Code ist eine kostenpflichtige SMS an eine Nummer, die der Anfragende nicht besitzen muss): nur erlaubte Ländervorwahlen und für AT/DE/CH nur Mobilfunkbereiche (keine Premium-/Sonderrufnummern), Same-Origin-Prüfung (`Origin`/`Referer` muss die eigene Site sein; fehlt beides, greifen nur die Limits), höchstens 3 Codes je Nummer und Stunde, 5 je Nummer und Tag, 5 je Client-IP und Stunde, 500 je Site und Tag, 30 Bestätigungsversuche je IP und Stunde (Filter `wpcap_sms_otp_limits`). Die Limits liegen wie alle Plugin-Limits in Transients (siehe oben: pro Site, ohne Object-Cache best effort) – bei hohem SMS-Preis zusätzlich ein Ausgabenlimit beim SMS-Anbieter setzen und `/wp-json/pass-captain/v1/sms/verify/request` am WAF begrenzen.
- Bestätigungsdatensätze (Nummer, Hashes) werden nach einem Tag gelöscht (SMS-Worker). Einwilligungen von vor DB 3.15.2 bleiben gültig und sind als „unverifiziert (Altbestand)“ gekennzeichnet; bei ausgeschalteter Bestätigung erfasste Einwilligungen heißen „unverifiziert (ohne Code erfasst)“ und erhalten nur SMS, solange die Bestätigung ausgeschaltet ist.

**IPv6:** Sitzplatz-Holds, Wartelisten und der Checkout zählen IPv6-Adressen je /64-Netz (`RateLimit::client_network()`), damit wechselnde Adressen innerhalb eines Anschlusses das Limit nicht zurücksetzen.

**Checkout** (`Sales\CheckoutThrottle`, alle Zähler atomar):

- je Client-Netz 30 angelegte Bestellungen pro Stunde (`wpcap_checkout_ip_limit`), je Netz und normalisierter E-Mail 8; die E-Mail wird wie bei Käuferlimits normalisiert (`+tag` entfällt, Gmail ohne Punkte, `googlemail.com` → `gmail.com`; abschaltbar mit `wpcap_buyer_email_aliases`);
- Versuche, die keine Bestellung anlegen (ausverkauft, Verkauf geschlossen, ungültiger Code, „viel los“), werden zurückgebucht (`RateLimit::refund()`); gegen Dauerklicken gilt zusätzlich ein Versuchslimit (4× so hoch, `wpcap_checkout_attempt_factor`);
- je Netz höchstens 10 unbezahlte Bestellungen innerhalb einer Haltezeit (höchstens 1 Stunde, `wpcap_checkout_unpaid_per_network`);
- **hinter Proxy/CDN ohne eingetragene vertrauenswürdige Proxys:** ist `REMOTE_ADDR` eine private/Loopback-Adresse (Load Balancer, Reverse Proxy), entfallen die Limits je Netz – sonst stünden alle Käufer unter einer Adresse und der Vorverkaufsstart würde seitenweit gedrosselt; es gilt nur Netz + E-Mail. Trägt die Anfrage nur einen Weiterleitungs-Header (`X-Forwarded-For`, `CF-Connecting-IP` …) von einer öffentlichen Adresse, werden die Limits je Netz verzehnfacht (`wpcap_checkout_proxy_limit_factor`; der Header ist fälschbar, deshalb kein völliges Aussetzen). Der Systemstatus warnt in beiden Fällen (auch noch 7 Tage nach dem letzten solchen Checkout), bis die Proxys eingetragen sind.

**Client-Adresse** (`RateLimit::client_ip()`, verwendet von allen Limits):

- Standard: `REMOTE_ADDR`. `X-Forwarded-For` & Co. werden **ignoriert**, weil jeder Client sie setzen kann.
- Hinter Reverse Proxy/CDN: unter *Einstellungen → Sicherheit* die Proxy-Adressen/CIDR-Bereiche eintragen (`trusted_proxies`, auch Konstante `WPCAP_TRUSTED_PROXIES` oder Filter `wpcap_trusted_proxies`) und den Header wählen (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`, `True-Client-IP`).
- Der Header wird nur ausgewertet, wenn die Anfrage tatsächlich von einem vertrauenswürdigen Proxy kommt. Bei `X-Forwarded-For` gilt der rechteste Eintrag, der selbst kein vertrauenswürdiger Proxy ist (links stehende Einträge sind fälschbar). Ungültige Werte fallen auf `REMOTE_ADDR` zurück.
- Der Filter `wpcap_client_ip` kann das Ergebnis weiterhin ersetzen.

## Rollen, Eventscoping und Datenminimierung

- **WooCommerce-Produkte (SEC-V2):** Nur Administratoren (`manage_options`) und Personen mit `wpcap_manage_events` für das jeweilige Event (`Access::can_access_event`) dürfen ein Produkt mit einer Ticketart verknüpfen, die Verknüpfung ändern oder entfernen. Shop-Manager ohne Eventzuweisung sehen in der Auswahl nur Ticketarten ihrer Events; eine bestehende Verknüpfung zu einem fremden Event wird schreibgeschützt angezeigt. Ein unzulässiger Wert wird nicht gespeichert (Fehlerhinweis im Produkt-Editor); andere Speicherwege eines angemeldeten Nutzers (WooCommerce-REST-API, Schnellbearbeitung, CSV-Import) setzen den gespeicherten Wert zurück (Audit `woocommerce_product_link_refused`).
- **Eventscoping:** Operative Rollen brauchen eine Zuweisung je Event (`wpcap_event_staff`) oder – nur für Einlass – je Termin (`wpcap_occurrence_staff`, Abschnitt „Einlasspersonal nur für diesen Termin“ im Termin-Admin). Terminpersonal darf an genau diesem Termin ein-/auschecken, den Scanner/die App/Offline-Pakete dieses Termins nutzen und die Basis-Teilnehmerliste sehen; es erhält nie eventweiten Zugriff (`Access::can_access_occurrence`).
- **Datenminimierung je Rolle** (`Security\DataPolicy`, abgeleitet aus den Capabilities):
  - Einlass (`wpcap_checkin`) und Veranstalter/Partner (`wpcap_view_reports` ohne Bestell-/Finanzrechte) erhalten keine Zahlungsdaten: keine Beträge, keine Anbieter-Referenzen, keine Rechnungsdaten – weder im Admin (Berichte zeigen nur Ticket- und Einlasszahlen), noch im REST-Bericht `events/{id}/reports/summary` (Entwickler-Keys behalten den vollen Bericht), noch im Bericht-CSV.
  - Finanzbuchhaltung sieht Zahlungsdaten, aber keine Teilnehmer-Zusatzangaben (Antworten, Teilnehmer-E-Mails) und keine Teilnehmerlisten.
  - Support (nur `wpcap_manage_orders`) sieht Käufer-/Teilnehmerdaten maskiert (`b•••@e•••.com`, `E. M•••`, keine Rechnungsdaten/Antworten). Pro Bestellung kann eine Freigabe mit Grund angefordert werden (15 Minuten, Audit `pii_unmasked`). Abschaltbar mit `support_mask_pii`.
- **Stapeldruck mit QR-Codes** (*Druck → Stapeldruck*, audit R2 #3/#5): erzeugt neue Ticketcodes und gibt sie der druckenden Person. Erlaubt nur mit Eventverwaltung (`wpcap_manage_events`) für das Event bzw. als Administrator, hinter der 2FA-Pflicht der Kategorien `bulk`/`exports` (`StepUp::check`, Aktion `print_batch_codes`); die Kassenrolle (`wpcap_manage_orders`) druckt nur ohne QR. Standardmäßig werden nur Tickets neu codiert, deren Code nie zugestellt wurde (Kassenverkauf ohne E-Mail, nicht übertragen, ohne Teilnehmer-E-Mail). Kundentickets nur mit Häkchen und Eingabe ihrer genauen Anzahl („N Kundentickets werden ungültig“); deren Inhaber erhalten die neuen Links per E-Mail (`tickets_resend`). Höchstens 500 Tickets je Auftrag (Filter `wp_pass_captain_print_batch_limit_v1`, größere Termine in Teilen). Die neuen Codes werden in **einer** Transaktion gespeichert, die erst nach vollständig geschriebener PDF-Datei (privates Temp-Verzeichnis) committet; Fehler, Exceptions oder ein Abbruch (Zeit-/Speicherlimit: Verbindung endet ohne COMMIT) lassen alle bisherigen Codes gültig. Wallet-Pässe werden danach per Job neu ausgestellt. Audit: `ticket_codes_rotated` (mit `customer_tickets`), `print_batch_codes_failed`, `print_batch_notify_failed`.
- **Gemeinsame Konten:** Organisatorische Regel: jede Person hat ein eigenes Konto; Konten werden nicht weitergegeben, Scanner-Geräte werden über kurzlebige Gerätekopplungen statt geteilter Logins betrieben. Technisch: Anmeldungen von Einlass-/Kassen-/Partnerkonten von einem weiteren Gerät/Netz werden erkannt und protokolliert (`concurrent_session_detected`, nur gekürzte Fingerabdrücke, keine IP-Adressen); mit `staff_single_session` werden andere Sitzungen beim Login beendet. Die Seite „Supportzugriff“ listet Konten mit gleichzeitigen Sitzungen.
- **Check-in zurücknehmen (Undo):** Personal ohne `wpcap_manage_orders` darf nur eigene Scans (eigenes Konto oder eigenes Gerät) innerhalb von `undo_window_minutes` (Standard 5, 0 = nie) zurücknehmen; ohne Zeitfenster dürfen Administratoren, Personal mit Bestellrechten und Rollen aus `undo_roles`. Jedes Undo braucht einen Idempotenzschlüssel (App: Pflichtfeld, Ticketseite: je Formular), eine Wiederholung nimmt keinen zweiten Einlass zurück. „Einlass-Konflikte“ zeigt die Tickets mit den meisten Undos (Passback-Muster).
- **Check-out:** verlangt Einlassrecht für Event/Termin; optional nur bestimmte Rollen (`checkout_roles`). Wiedereinlass nach Check-out für Ticketarten ohne eigene Einlassregel per `reentry_after_checkout`/`max_reentries`; erstattete Tickets werden nie wieder eingelassen.
- **Erstattung nach Check-in** (`refund_after_checkin`: `allow` | `approval` | `deny`): gilt für manuelle Erstattungen und automatische Erstattungen bei Terminabsage (dort werden unbenutzte Tickets immer erstattet, benutzte je nach Regel übersprungen oder zur Zweitfreigabe gestellt). Bei `approval` muss eine andere Person freigeben. Eingecheckte Tickets bleiben `checked_in` (Historie) mit `refunded=1`. WooCommerce-Erstattungen liest dieselbe Einstellung.

## Zwei-Faktor-Pflicht und Supportzugriff

- 2FA wird über das Two-Factor-Plugin oder den Filter `wpcap_user_has_2fa` erkannt (wie bei Erstattungen, `require_2fa_for_refunds`).
- Zusätzlich je Kategorie einstellbar (*Einstellungen → Sicherheit*): `mfa_settings` (Einstellungsformulare), `mfa_secrets` (Zugangsdaten, auch im Kern-Formular), `mfa_exports` (CSV/Teilnehmerlisten/Buchhaltung/Audit-Log/Netzwerkbericht), `mfa_roles` (Personal- und Rollenzuweisungen inkl. WordPress-Profil/Benutzerliste, Supportzugriff), `mfa_bulk` (Event-/Terminabsage, Stapeldruck, Warteschlangen-Aktionen), `mfa_api_keys` (Entwickler-/Partner-Keys, auch REST). Durchsetzung zentral vor dem Handler (`Security\StepUp`: `admin_init`, `rest_pre_dispatch`, `user_profile_update_errors`); blockierte Versuche: Audit `mfa_required_blocked`.
- **Supportzugriff:** Administratoren vergeben unter *Pass Captain → Supportzugriff* zeitlich begrenzte Rechte (`wpcap_manage_orders`, `wpcap_checkin`, `wpcap_view_reports`; höchstens `support_access_max_hours`) mit Pflichtgrund. Ablauf wird bei jeder Prüfung erzwungen; der stündliche Cron markiert abgelaufene Zugriffe. Vergabe, Widerruf und Ablauf stehen im Audit-Log.

## Audit-Log

- Protokolliert u. a. Exporte und Downloads (`export_downloaded`), Personal-/Terminzuweisungen, Änderungen an WordPress-Rollen (`user_role_changed/added/removed`), direkt vergebene/entzogene Plugin-Capabilities (`user_capability_granted/revoked`), Capability-Änderungen von Plugin-Rollen (`role_capabilities_changed`), lesende Zugriffe auf personenbezogene Daten (`pii_read`: Bestelldetail, Ticketsuche, Personal-REST-Abrufe, Audit-Log-Ansicht). Lesezugriffe werden zusammengefasst: je Person, Ansicht und Objekt höchstens alle 15 Minuten, höchstens `audit_read_limit_per_hour` je Person und Stunde (danach ein Eintrag `pii_read_throttled`).
- Ansicht *Pass Captain → Audit-Log* (Capability `wpcap_view_audit`, Administratoren; alternativ `manage_options`) mit Filtern nach Aktion, Objekt, Objekt-ID, Benutzer, Zeitraum, Seitenweise; CSV-Export wird selbst protokolliert (`audit_log_exported`).

## Berechtigungen (automatisch geprüft)

`tests/integration/modules/zz_w5_permissions.php` enthält eine Tabelle aller REST-Routen und aller admin-post/admin-ajax-Aktionen. Jede Route ist als `public` (mit Begründung), `token` (Handler verlangt Geheimnis/Signatur), Capability, Gerät, Entwickler- oder Partner-Key deklariert. Der Test schlägt fehl, wenn eine Route fehlt oder wenn Gast, Abonnent, Mitarbeitende eines anderen Events, widerrufene/abgelaufene/fremde Entwickler-Keys, widerrufene Geräte oder Partner-Keys eines anderen Veranstalters Erfolg haben oder Daten ändern.

Zusätzlich prüft `tests/integration/modules/zzzzzz_y1_security.php` den **Geltungsbereich**: Für jede schreibende, per Capability geschützte Route mit Objekt-IDs (Pfad oder Body) gibt es einen Fall, in dem Personal von Event A eine ID von Event B (Ticketart, Ressource, Pass, Buchung, Bestellung, Ticketcode, Key) oder einen globalen Geltungsbereich (Service-Regel für alle Ressourcen, `any`) verwendet; erwartet wird Ablehnung ohne Datenänderung. Eine neue solche Route ohne Fall (oder begründete Ausnahme) lässt den Test scheitern.

## Sicherheitsprozess

### Schwachstellen melden

- Meldungen ausschließlich **privat über GitHub Security Advisories des Repositories** („Security“ →
  „Report a vulnerability“). Entscheidung offen: zusätzlicher privater Kanal (z. B. Sicherheits-Postfach)
  – bis dahin gibt es keinen.
- Keine Ticketcodes, Kundendaten, API-Schlüssel oder funktionsfähigen Exploits in öffentlichen Issues,
  Foren oder Pull Requests. Öffentliche Issues mit solchen Inhalten werden ausgeblendet und als Advisory
  weitergeführt.
- Hilfreich: betroffene Version, Konfiguration (WooCommerce, Module), Reproduktionsschritte, Auswirkung.

### Reaktionszeiten (Zielwerte)

| Schritt | Ziel |
|---|---|
| Eingangsbestätigung | 3 Werktage |
| Erste Bewertung (Schweregrad nach CVSS, betroffene Versionen) | 7 Werktage |
| Fix kritisch/hoch | Patch-Release innerhalb von 14 Tagen |
| Fix mittel/niedrig | mit dem nächsten regulären Release, spätestens 90 Tage |
| Veröffentlichung | Advisory (mit CVE, sofern vergeben) nach Verfügbarkeit des Fixes, abgestimmt mit der meldenden Person |

Fixes erhalten die aktuelle und die vorherige Minor-Version ([SUPPORT.md](SUPPORT.md)). Entscheidung offen:
die Zielwerte sind Vorschläge und vom Betreiber zu bestätigen.

### Incident-Handling

1. **Eindämmen:** betroffene Funktion abschalten (Zahlungsart, Modul, Webhook-Abonnement, Partner-/
   Entwickler-Key, Gerätekopplung widerrufen), bei aktivem Missbrauch Site in den Wartungsmodus; Logs,
   Audit-Log (*Pass Captain → Audit-Log*, CSV-Export) und Datenbankstand sichern, bevor etwas geändert wird.
2. **Geheimnisse rotieren:** Anbieter-Schlüssel (Stripe, PayPal, Mollie, Twilio, Wallet, Webhook-Secrets)
   beim Anbieter neu erzeugen und eintragen; den Vault-Schlüssel wechseln (`PASS_CAPTAIN_SECRET_KEY_PREVIOUS`
   + neuer `PASS_CAPTAIN_SECRET_KEY`, Admin-Seite aufrufen, alte Konstante entfernen – siehe
   „Schlüsselwechsel“ oben); Entwickler-/Partner-Keys und Gerätetokens widerrufen; bei Verdacht auf
   `wp-config.php`-Abfluss auch WordPress-Salts erneuern (Sitzungen enden, gespeicherte Secrets ohne eigene
   Vault-Konstante müssen neu eingegeben werden).
3. **Bereinigen und prüfen:** Ursache beheben (Patch-Release), `wp pass-captain diagnose --strict`
   ausführen, Zahlungen und Erstattungen beim Anbieter abgleichen, betroffene Tickets widerrufen und neu
   ausstellen.
4. **Benachrichtigen:** Bei Verletzung personenbezogener Daten Meldung an die Aufsichtsbehörde binnen 72
   Stunden (Art. 33 DSGVO) und gegebenenfalls Betroffene (Art. 34) – Aufgabe des Betreibers als
   Verantwortlicher. Nutzer des Plugins über GitHub Security Advisory und Release-Notes informieren.
5. **Nachbereiten:** Postmortem innerhalb von 14 Tagen (Zeitleiste, Ursache, Auswirkung, Maßnahmen,
   Tests, die den Fehler künftig finden); Maßnahmen als Issues verfolgen.

### Abhängigkeiten

- CI (`ci.yml`, Job `static`) führt bei jedem Push `composer audit --locked` aus (Laufzeit- und
  Dev-Pakete); bekannte Schwachstellen lassen den Build scheitern. Pull Requests prüft zusätzlich
  `actions/dependency-review-action` (ab „moderate“), gitleaks scannt Historie und ZIP.
- Mitgelieferte Fremdbibliotheken ohne Composer (`assets/vendor/`, z. B. jsQR) werden bei jedem Minor-
  Release auf neue Versionen und Advisories geprüft.
- Kadenz: monatlich Composer- und GitHub-Actions-Abhängigkeiten aktualisieren (Pinning beibehalten,
  `composer.lock` committen, reproduzierbaren Build prüfen), Sicherheitsupdates sofort. Ein automatischer
  Update-Bot (Dependabot o. ä.) ist nicht eingerichtet – Entscheidung offen.

### Kompatibilitätsprüfungen

- Zu jeder neuen WordPress- und WooCommerce-Hauptversion (inkl. Release Candidates) Installationstest und
  Integrationssuite gegen die neue Version laufen lassen, `Tested up to`/`WC tested up to` erst nach grüner
  Prüfung anheben ([COMPATIBILITY.md](COMPATIBILITY.md)).
- Mindestens monatlich die CI-Matrix mit „latest“ neu ausführen (ein Lauf ohne Codeänderung reicht), damit
  Brüche durch neue Upstream-Versionen auffallen.

## Unabhängige Prüfung (ausstehend)

Noch ausstehend (ursprünglich „vor 1.0“ geplant, die aktuelle Version ist bereits 1.x): Bedrohungsmodell, Rollen-/Nonce-Prüfung, SQL-Transaktionen, Datenschutzexport und -löschung, Offline-Konflikte, Abhängigkeitsprüfung und Wiederherstellung müssen extern oder durch eine dokumentierte unabhängige Prüfung bewertet werden. Die technischen Release-Gates stehen in [RELEASE.md](RELEASE.md). Dieses Dokument ist keine Sicherheitszertifizierung.

## Ausgehende HTTP-Aufrufe (A202)

Alle ausgehenden HTTP-Aufrufe in `src/` laufen über den zentralen Guard `PassCaptain\OutboundHttp`
(`request()` bzw. `check()` für den rohen HTTP/2-Client von APNs):

| Aufrufer | Ziel | Regel |
|---|---|---|
| Webhooks (`Webhooks\Dispatcher`) | konfigurierbare URL | HTTPS, öffentliche Adresse nach DNS, festgelegt; Antwort ≤ 64 KB, 10 s |
| Fiskal-Anbieter (`Fiscalization\Providers\HttpClient`: fiskaltrust, efsta) | konfigurierbare `base_url` (fiskaltrust: Pflicht; die Sandbox nur mit `environment` = `sandbox`, kein stiller Rückfall, A655) | HTTPS, öffentliche Adresse; einzige Ausnahme die feste lokale efsta-EFR-Adresse `http://127.0.0.1:5618`; Antwort ≤ 512 KB, 15 s |
| SMS über generischen HTTP-Anbieter (`Sms\HttpProvider`) | konfigurierbarer Endpunkt | HTTPS, öffentliche Adresse; Antwort ≤ 64 KB, 15 s; ein abgelehntes Ziel ist ein dauerhafter Fehler |
| SMS über Twilio | `api.twilio.com` | fester Host |
| Google Wallet | `walletobjects.googleapis.com`; `token_uri` des Dienstkontos nur `oauth2.googleapis.com`, `accounts.google.com`, `www.googleapis.com` | feste Hosts |
| Apple Wallet (APNs, cURL direkt) | `api.push.apple.com`, `api.sandbox.push.apple.com` | feste Hosts, `CURLOPT_RESOLVE`, nur HTTPS, keine Weiterleitung, Antwort ≤ 64 KB; beachtet `WP_HTTP_BLOCK_EXTERNAL`/`WP_ACCESSIBLE_HOSTS` und den WordPress-Proxy (`WP_PROXY_*`, CONNECT-Tunnel) |
| Stripe, PayPal, Mollie | `api.stripe.com`, `api-m.paypal.com`/`api-m.sandbox.paypal.com`, `api.mollie.com` | feste Hosts (die URLs sind nicht konfigurierbar) |

Partner-API und Voucher-Captain-Bridge rufen nichts per HTTP auf (Partner fragen die REST-API ab, die Bridge
läuft im selben WordPress); der Affiliate-Captain-Adapter ebenso.

Der Guard erzwingt:

- **Nur HTTPS.** HTTP (oder eine private Adresse) nur für exakte Origins `schema://host:port`, die ein
  Anbieter-Adapter fest mitgibt (efsta lokal) oder die die Site per Filter `wpcap_outbound_http_exceptions`
  freigibt (z. B. efsta EFR auf einem Kassenrechner im LAN). Keine Platzhalter, keine Benutzerdaten in der URL.
- **Feste Hosts** für Anbieter-Aufrufe: jeder andere Host wird abgelehnt (`wpcap_http_host_not_allowed`).
- **Keine internen Ziele.** `localhost`, `*.localhost`, `*.local`, `*.internal`, `*.localdomain`, `*.home.arpa`,
  einteilige Hostnamen und nicht-kanonische IP-Schreibweisen (`2130706433`, `0x7f.1`, `127.1`) werden abgelehnt.
  Der Host wird **einmal** aufgelöst (A und AAAA); **jede** Adresse muss öffentlich sein. Gesperrt sind u. a.
  0/8, 10/8, 100.64/10, 127/8, 169.254/16 (inkl. Metadaten 169.254.169.254), 172.16/12, 192.0.0/24,
  192.168/16, 198.18/15, Dokumentationsnetze, Multicast, 240/4, 168.63.129.16 (Azure) sowie ::, ::1,
  fc00::/7 (inkl. AWS-IMDS fd00:ec2::254), fe80::/10, fec0::/10, ff00::/8, 2001::/23, 2001:db8::/32,
  64:ff9b:1::/48 und IPv4-übersetzte Adressen (`::ffff:0:0:0/96`); in
  IPv4-mapped (`::ffff:a.b.c.d`), NAT64 (`64:ff9b::/96`) und 6to4 (`2002::/16`) wird die eingebettete
  IPv4-Adresse geprüft.
- **DNS-Rebinding:** Die Verbindung wird über `CURLOPT_RESOLVE` (Action `http_api_curl`) auf die geprüfte
  Adresse festgelegt; eine zweite, andere DNS-Antwort wird nie verwendet. Ohne cURL-Transport lehnt der Guard
  ab (`wpcap_http_unpinned`, abschaltbar mit `wpcap_outbound_allow_unpinned` – nicht empfohlen). Zusätzlich
  bleibt `reject_unsafe_urls` (WordPress-eigene Prüfung) aktiv.
- **Keine Weiterleitungen** (`redirection` 0, `CURLOPT_FOLLOWLOCATION` aus); eine 3xx-Antwort ist ein Fehler
  `wpcap_http_redirect`. Ein Aufrufer kann bis zu drei Weiterleitungen erlauben; jedes Ziel wird dann erneut
  vollständig geprüft, bei Origin-Wechsel entfallen Authorization- und Schlüssel-Header.
- **Zeitlimits:** Standard 10 s, höchstens 30 s; Verbindungsaufbau höchstens 5 s.
- **Antwortgröße:** Standard 1 MB (`limit_response_size`), je Aufrufer kleiner; größere Antworten ergeben
  `wpcap_http_too_large`.
- **Schwärzung:** Fehlermeldungen (auch im Webhook-Zustellprotokoll und in SMS-Fehlern) enthalten keine Werte
  von Authorization-, Cookie-, Schlüssel-, Token- oder Signatur-Headern, keine `Bearer`/`Basic`-Zugangsdaten,
  keine Benutzerdaten in URLs und keine geheimen Query-Parameter (`key`, `token`, `secret`, `password`,
  `signature` …). `OutboundHttp::redact()` und `redact_headers()` stehen für eigene Logs bereit.
- Abgelehnte Anfragen lösen die Action `wpcap_outbound_http_blocked` (Code, geschwärzte URL, Kontext) aus.
- Der Server muss die Zielhosts selbst auflösen können (auch hinter einem ausgehenden Proxy, `WP_PROXY_HOST`);
  ist ein Host nicht auflösbar, lehnt der Guard ab (`wpcap_http_dns`). Für Split-Horizon-DNS liefert der Filter
  `wpcap_outbound_resolve` die Adressen.

Getestet in `tests/integration/modules/zz_a202.php`: DNS über `wpcap_outbound_resolve`, Transport über
`pre_http_request`/HTTP-Stubs, jeder Aufrufer, Rebinding (zweite DNS-Antwort privat), Weiterleitungen,
Größen- und Zeitlimits, Schwärzung.

Der generische Varnish-Purge (A446, standardmäßig aus) läuft ebenfalls über den Guard; ein lokaler
Varnish-Endpunkt (`http://127.0.0.1:6081`) gilt nur als Ausnahme, weil die Website ihn ausdrücklich im Filter
`wpcap_page_cache_varnish` einträgt. WAF-/Bot-Schutz-Ausnahmen für Zahlungs-Webhooks und den Apple-Wallet-
Webservice sowie die Seitencache-Ausschlüsse persönlicher Seiten: [OPERATIONS.md](OPERATIONS.md#seitencache-und-cdn).

## Ratenlimits hinter Proxys und mit mehreren Workern

**Zähler (`PassCaptain\RateLimit`).** Es gilt ein festes Zeitfenster je Bucket. Jeder Aufruf zählt;
erlaubt sind genau `limit` Aufrufe je Fenster, egal wie viele Worker gleichzeitig zählen:

| Backend | Wann | Atomar |
|---|---|---|
| `cache` | Ein persistenter Objekt-Cache ist installiert (`wp_using_ext_object_cache()`). Gezählt wird mit `wp_cache_add()` und `wp_cache_incr()`, das Fenster ist die TTL. | Ja, mit einem Redis oder Memcached, den alle Webserver gemeinsam nutzen. |
| `db` | Standard ohne Objekt-Cache. Eine Zeile je Bucket und Fenster in `wpcap_rate_limits`, gezählt mit einem einzigen `INSERT … ON DUPLICATE KEY UPDATE hits=LAST_INSERT_ID(hits+1)`. | Ja, auch mit mehreren Webservern an derselben Datenbank. |
| `transient` | Nur als Rückfall, wenn die Tabelle noch fehlt (vor dem Datenbank-Upgrade) oder die Datenbank einen Fehler meldet. | Nein. |

Schlägt `wp_cache_incr()` fehl (Schlüssel verdrängt, Cache nicht erreichbar), wird in der Datenbank
gezählt. Abgelaufene Zeilen löscht der Cron `wpcap_cleanup_reservations` (`RateLimit::purge()`), dazu
gelegentlich ein Aufruf. Die Bucket-Namen werden mit einem Schlüssel aus `wp_salt()` gehasht; IP-Adressen
stehen nie im Klartext in Datenbank oder Cache. Mit dem Filter `wpcap_rate_limit_backend` lässt sich das
Backend erzwingen.

**Fehlversuchs-Zähler (A288).** Zähler, die nur Fehlschläge zählen, prüfen vorher mit `RateLimit::hits()`
(liest, ohne zu zählen) und zählen den Fehlschlag danach mit `RateLimit::allow()`: Buchungstokens der
Service-Selbstbedienung, Partnercodes von Affiliate Captain, Sperre der Partner-API nach Fehlanmeldungen.
Auch das Ratenlimit der Entwickler-API-Keys (je Key und Uhrminute), das Limit für Überweisungsbestellungen
je Client und die „einmal je Minute protokollieren“-Merker der Partner-API laufen über `RateLimit`; keiner
dieser Zähler nutzt mehr Transients (keine Option-Zeile je Key und Minute, kein verlorenes Hochzählen
paralleler Anfragen).

Nachweis: `tests/integration/modules/zz_a186.php` startet 8 PHP-Prozesse, die gleichzeitig je 30 Mal
denselben Bucket mit Limit 50 zählen. Erlaubt werden genau 50 Aufrufe, gezählt werden alle 240.

**Client-Adresse (`PassCaptain\ClientIp`).** Ohne Konfiguration gilt immer `REMOTE_ADDR`.
Weiterleitungs-Header wie `X-Forwarded-For`, `Forwarded` oder `CF-Connecting-IP` werden standardmäßig
nie ausgewertet, weil jeder Client sie setzen kann. Ausgewertet wird ein Header nur, wenn die direkte
Gegenstelle (`REMOTE_ADDR`) in **Einstellungen → Allgemein → Proxy / Client-Adresse →
Vertrauenswürdige Proxys** steht. Dort stehen IP-Adressen oder CIDR-Bereiche für IPv4 und IPv6. Alternativ
geht das über die Konstante oder Umgebungsvariable `WPCAP_TRUSTED_PROXIES` oder den Filter
`wpcap_trusted_proxies`. Welcher Header gilt, legt die Einstellung `client_ip_header` fest
(`WPCAP_CLIENT_IP_HEADER`, Filter `wpcap_client_ip_header`):

- `x-forwarded-for` (Standard) und `forwarded` (RFC 7239): Die Kette wird von rechts gelesen.
  Vertrauenswürdige Proxys werden übersprungen; die **rechteste nicht vertrauenswürdige** Adresse ist der
  Client. Was der Client selbst links einträgt, wird ignoriert. Ist ein Eintrag ungültig (zum Beispiel
  `unknown` oder ein verschleierter Name), gilt `REMOTE_ADDR`.
- `cf-connecting-ip`: die von Cloudflare gesetzte Adresse. Dafür müssen die Cloudflare-IP-Bereiche als
  vertrauenswürdige Proxys eingetragen sein.

Die aufgelöste Adresse nutzen alle Ratenlimits, die Einwilligungsnachweise und die Partner-API. Der
bisherige Filter `wpcap_client_ip` kann das Ergebnis weiterhin überschreiben.

**IPv6-Clients (A280).** Ein Anschluss bekommt meist ein ganzes /64-Netz (oft /56 oder /48) und kann darin
beliebig viele Adressen nutzen. Für Ratenlimits zählt deshalb nicht die einzelne IPv6-Adresse, sondern ihr
/64-Präfix (`ClientIp::limit_key()`, `RateLimit::client()`; Filter `wpcap_rate_limit_ipv6_prefix`, 32–128;
IPv4 und IPv4-gemappte Adressen bleiben unverändert). Anfragen, die Kontingent belegen – Checkout,
Sitzplatz- und Service-Holds, Warteliste (`RateLimit::allow_client()`) – zählen bei IPv6 zusätzlich im
/48-Netz mit dem Vierfachen des Limits (Filter `wpcap_rate_limit_ipv6_network_factor`), damit das Durchwechseln
der /64-Netze eines Anschlusses das Limit nicht vervielfacht. Auch die Sperre der Partner-API nach
Fehlanmeldungen gilt je /64. Einwilligungsnachweise und das Audit-Log verwenden weiter die volle bzw. die
anonymisierte Adresse.

**Empfehlungen fürs Hosting**

- **Mehrere Webserver oder PHP-FPM-Worker:** einen persistenten Objekt-Cache mit Redis oder Memcached
  einrichten, den alle Server gemeinsam nutzen (zum Beispiel Redis Object Cache). Ein Objekt-Cache, der
  nur auf einem Server liegt (APCu-Drop-ins), ist zwischen Servern nicht atomar. In diesem Fall
  `add_filter( 'wpcap_rate_limit_backend', fn() => 'db' );` setzen. Ohne Objekt-Cache ist die
  Datenbank-Tabelle bereits atomar; sie kostet einen Schreibzugriff je begrenztem Aufruf.
- **Reverse Proxy, Load Balancer oder CDN:** genau dessen Adressen beziehungsweise Bereiche als
  vertrauenswürdige Proxys eintragen und den passenden Header wählen. Keine breiten Bereiche wie
  `0.0.0.0/0` eintragen. Interne Bereiche wie `10.0.0.0/8` nur dann, wenn kein Client sie erreichen kann.
  Der Proxy muss `X-Forwarded-For` anhängen und nicht ungeprüft durchreichen; bei nginx `proxy_set_header
  X-Forwarded-For $proxy_add_x_forwarded_for;`. Alternativ setzt der Webserver `REMOTE_ADDR` selbst
  korrekt, mit nginx `real_ip` oder Apache `mod_remoteip`. Dann bleibt die Liste leer.
- **Ohne Proxy-Konfiguration** teilen sich alle Käufer hinter einem Proxy dessen Adresse und damit dessen
  Limit. Das ist sicher, kann bei großen Vorverkäufen aber zu frühen 429-Antworten führen.
- Hosting-seitige Limits (WAF, nginx `limit_req`, CDN-Regeln) bleiben die erste Verteidigungslinie. Die
  Limits des Plugins sind die zweite.
## Rollen und Capabilities

Das Plugin legt diese Rollen an (`Installer::role_definitions()`; bei Updates werden Rechte nur ergänzt, nie
entzogen). Jede Rolle hat zusätzlich `read`. Administratoren (`manage_options`) erhalten alle `wpcap_*`-Rechte
und sind nie auf Events oder Bereiche beschränkt.

| Capability | Wofür | Ticket-Administration | Veranstaltungsleitung | Vor-Ort-Kasse | Einlasspersonal | Veranstalter | Leistungserbringer | Finanzbuchhaltung | Support |
|---|---|---|---|---|---|---|---|---|---|
| `wpcap_manage_events` | Events, Termine, Ticketarten, Teams, Service-Zeitfenster und Zuweisungen verwalten | ✓ | ✓ | | | | | | |
| `wpcap_manage_orders` | Bestellungen, Tickets, Kasse, Gästelisten, Erstattung anfragen | ✓ | ✓ | ✓ | | | | | ✓ |
| `wpcap_checkin` | Einlass (Web-Scanner, Scanner-App, Offline-Scanner), Check-in-Liste | ✓ | ✓ | ✓ | ✓ | ✓ | | | |
| `wpcap_view_reports` | Berichte und Kennzahlen (nur Summen) | ✓ | ✓ | | | ✓ | | ✓ | |
| `wpcap_refund` | Erstattungen ausführen bzw. anstoßen | ✓ | ✓ | | | | | | |
| `wpcap_approve_refunds` | Erstattungen freigeben (Vier-Augen) | ✓ | | | | | | ✓ | |
| `wpcap_view_finance` | Rechnungen, Kassenbelege, Buchhaltungsexport – ohne Event-Zuweisung (Bereich nach A119) | ✓ | | | | | | ✓ | |
| `wpcap_service_bookings` | Eigene Leistungen: Zeitfenster und Buchungen ansehen, bestätigen, als nicht erschienen markieren | ✓ | | | | | ✓ | | |

Rollen-Slugs: `wpcap_ticket_admin`, `wpcap_event_manager`, `wpcap_box_office`, `wpcap_checkin_staff`,
`wpcap_partner` (Veranstalter, also Organisator eines Events), `wpcap_service_provider` (Leistungserbringer,
z. B. Workshopleitung, Fotograf:in, Behandlungsraum), `wpcap_finance`, `wpcap_support`.

**Rollennamen (A362):** In der Datenbank stehen die unübersetzten Quellnamen (`Installer::ROLE_NAMES`, wie
WordPress „Editor“ speichert). Angezeigt werden sie übersetzt in der Sprache der ansehenden Person
(`translate_user_role()`, Kontext „User role“, beantwortet aus dem Katalog von `pass-captain`). Namen, die
eine frühere Version bei der Aktivierung übersetzt gespeichert hat, werden beim nächsten Aufruf auf den
Quellnamen zurückgesetzt; von Betreibenden vergebene Namen bleiben unverändert.

**Leistungserbringer (A143):** Die Rolle hat nur `wpcap_service_bookings`. Zugang entsteht ausschließlich über
einen Leistungserbringer-Datensatz (A133, **Pass Captain → Service-Zeitfenster**, Tabellen
`wpcap_service_providers`/`wpcap_resource_providers`): Ist ein *aktiver* Datensatz mit dem WordPress-Benutzer
verknüpft (`user_id`), erreicht die Person genau die Ressourcen, denen dieser Datensatz zugeordnet ist
(`ServiceProviders::resource_ids_for_user()`, `Access::can_access_resource()`). Verknüpfen und Zuordnen darf nur,
wer `wpcap_manage_events` für das Event hat – einen bereits zugeordneten Datensatz ändern (z. B. den
Benutzer umhängen) nur, wer alle Events seiner Ressourcen verwaltet (A145); beides wird auditiert (`service_provider_created`/`service_provider_updated`
mit `user_id` vorher/nachher, `service_resource_providers_set`). Derselbe Datensatz schützt vor Doppelbuchung und
bestimmt die Team-Mails der Ressource. Ohne verknüpften Datensatz sieht die Person nichts; mit ihm zeigt
**Pass Captain → Meine Leistungen** die Zeitfenster und Buchungen dieser Ressourcen (ab gestern). Je Buchung:
**Bestätigen** nur für eine aktive Reservierung (`held`) einer kostenlosen Ressource (kostenpflichtige werden
mit der Zahlung bestätigt; läuft über `ServiceBookings::confirm()` mit allen Sperren und Regeln) und
**Nicht erschienen** nur für eine bestätigte Buchung ab Beginn des Zeitfensters (`no_show`, keine Erstattung).
Jede Aktion prüft serverseitig (`ServiceBookings::set_booking_status()`), schreibt `service_booking_confirmed`
bzw. `service_booking_no_show` ins Audit-Log und löst `wpcap_service_booking_status_changed` aus. Personen mit
`wpcap_manage_events` für das Event haben dieselben Aktionen in der Buchungsliste der Service-Zeitfenster.
Andere Events, Bestellungen, Tickets, Berichte oder fremde Ressourcen erreicht die Rolle nicht; die Verknüpfung
vergibt nie selbst ein Recht (die Rolle muss vorhanden sein), ein deaktivierter Datensatz entzieht den Zugang.

**Wen das Event-Team aufnehmen kann (A471, A636):** Event-Team, Einlassbereiche und Terminzuweisungen nehmen
nur Mitglieder der aktuellen Site auf (`StaffAssignments::assignable_user()`; im Netzwerk würde eine Zuweisung
sonst ein beliebiges Netzwerkkonto mit `wpcap_checkin` in die Site holen). Eine Veranstaltungsleitung ohne
`list_users` kann nur Personen zuweisen, die bereits eine Pass-Captain-Rolle bzw. -Capability haben;
Administratoren dürfen jedes Mitglied der Site zuweisen (Einlasspersonal ohne Plugin-Rolle). Unbekannte
Konten, Konten anderer Sites und Konten ohne Rolle bekommen dieselbe Meldung (auch bei fehlendem Einzelrecht
für Nicht-Administratoren), und die Eventberechtigung wird vor dem Konto geprüft – die Zuweisung verrät nicht,
ob ein Login oder eine E-Mail-Adresse existiert.

**Rollenentzug und Löschen von Benutzern (A277, A278):** Einlasspersonal ohne Plugin-Rolle bekommt `wpcap_checkin`
mit der Team-Zuweisung als Benutzer-Capability. Damit Zugänge eine Rollenänderung nicht überleben, nimmt
`UserAccess` beim Entzug einer Rolle (`set_user_role` mit einer wegfallenden Rolle, `remove_user_role`) diese
Benutzer-Capability zurück, löscht Event-Team-Einträge (samt Terminzuweisungen) für Rechte, die die Person
danach nicht mehr hat, und widerruft ohne `wpcap_checkin` alle Scanner-Geräte, offene Kopplungscodes und
Einlassbereichs-Zuweisungen; Entwickler-API-Schlüssel für Events, die die Person nicht mehr lesen darf, werden
widerrufen. Was eine verbleibende Rolle weiter erlaubt, bleibt erhalten; das Hinzufügen einer
Rolle ändert nichts. Wird ein Benutzer gelöscht (bzw. in Multisite aus der Site entfernt), wird alles davon
zurückgenommen. Nach einem Rollenwechsel (auch z. B. von „Abonnent“ zu „Kunde“) muss die Einlass-Zuweisung
also neu vergeben werden. Jede Rücknahme steht als `user_access_revoked` im Audit-Log; Filter
`wpcap_revoke_access_on_role_change`.

## Rollen, Zugriffsbereiche und Prüfreihenfolge

Jede Admin-Seite, jeder admin-post-Handler und jede authentifizierte REST-Route prüft in dieser Reihenfolge
(zentral in `PassCaptain\Access`):

1. **Capability der Rolle** (`wpcap_manage_events`, `wpcap_manage_orders`, `wpcap_checkin`, `wpcap_view_reports`,
   `wpcap_view_finance`, `wpcap_refund`, `wpcap_approve_refunds` …).
2. **Event-Zuweisung** in `wpcap_event_staff` für genau diese Capability (`Access::can_access_event()`).
   Finanzbuchhaltung (`wpcap_view_finance`) liest Berichte, Rechnungen und Kassenbelege ohne Zuweisung.
   Leistungserbringer (`wpcap_service_bookings`) brauchen statt der Event-Zuweisung einen mit ihrem Benutzer
   verknüpften, aktiven Leistungserbringer-Datensatz, der der Ressource zugeordnet ist
   (`Access::can_access_resource()`).
2b. **Engere Zuweisungen (A144)** unter **Pass Captain → Zuweisungen** (je Event; nur mit
   `wpcap_manage_events` für das Event, Terminzuweisungen zusätzlich für den Termin):
   - **Termine:** Eine Person aus dem Event-Team wird für ein Recht (Einlass, Bestellungen/Kasse, Event
     verwalten, Berichte) auf einzelne Termine beschränkt (`wpcap_staff_assignments`, Bereich `occurrence`).
     Sobald für (Person, Event, Recht) mindestens eine Terminzuweisung besteht, lassen alle terminbezogenen
     Prüfungen mit diesem Recht nur noch diese Termine zu – Scannen (Web, App, Offline-Paket), Tickets,
     Bestellungen (alle Termine der Bestellung), Teilnehmer-/No-Show-Listen, Terminabrechnung, Bearbeiten und
     Absagen von Terminen (`Access::can_access_occurrence()`, `occurrence_in_scope()`, `order_in_scope()`).
     Listen und Exporte ohne konkretes Recht (`occurrence_scope_sql()`) nutzen die Vereinigung über alle
     Team-Rechte der Person: Sie werden erst gefiltert, wenn jedes ihrer Rechte beschränkt ist. Event-weite
     Kennzahlen (REST `/events/{id}/reports/summary`) gibt es nur, wenn das Berichtsrecht nicht beschränkt ist.
     Eine Terminzuweisung setzt den Team-Eintrag für dasselbe Recht voraus und vergibt nie selbst Zugriff;
     wird der Team-Eintrag entfernt, verschwinden ihre Terminzuweisungen mit.
   - **Einlassbereiche:** Zuordnung von Personen zu Bereichen des Einlassmoduls
     (`wpcap_admission_area_staff`); wer einem Bereich zugewiesen ist, scannt für dieses Event nur dort
     (`Admission\Areas::check()`, Bereichsauswahl im Scanner, Gerätekopplung).
   - **Leistungen:** Leistungserbringer-Datensätze je Ressource (A133, siehe oben); die Seite zeigt sie je
     Ressource, verwaltet werden sie auf der Seite Service-Zeitfenster.

   Alle Änderungen landen im Audit-Log (`staff_assignment_added`/`staff_assignment_removed`,
   `admission_area_staff_added`/`admission_area_staff_removed`, `service_resource_providers_set`). Administratoren sind nie beschränkt.
3. **Zugriffsbereich (A119, optional je Person)** im WordPress-Profil unter „Pass Captain: Zugriffsbereich“
   (Benutzeroption je Site `{präfix}wpcap_access_scope`, z. B. `wp_2_wpcap_access_scope`, Filter
   `wpcap_access_scope`). Seit Schema 3.10.19 (A470) gilt ein Bereich nur auf der Site, auf der er gesetzt
   wurde: Benutzer sind netzwerkweit, Partner-IDs und Spielorte gehören aber zu einer Site. Das Update kopiert
   den früheren netzwerkweiten Eintrag auf jede Site, deren Mitglied die Person ist (jede Site bei ihrem
   eigenen Update; vorhandene Site-Einträge bleiben) – am wirksamen Zugriff ändert sich dabei nichts; danach
   sollten Administratoren die Bereiche je Site prüfen, weil Partner-IDs auf anderen Sites andere Partner
   bezeichnen. Auf Einzelinstallationen wird der alte Schlüssel entfernt:
   - **Spielorte:** nur Termine, deren Ort (`occurrences.location`, ohne Groß-/Kleinschreibung) aufgeführt ist.
     Ein Event ist erreichbar, sobald einer seiner Termine im Bereich liegt; alles mit Terminbezug – Tickets,
     Check-ins, Bestellungen (alle Termine der Bestellung), Teilnehmer- und No-Show-Listen, Kasse,
     Gästelisten, Terminabrechnung, Berichtszeilen – wird auf Terminebene geprüft
     (`Access::can_access_occurrence()`, `Access::occurrence_in_scope()`, `Access::occurrence_scope_sql()`).
     Event-weite Kennzahlen (REST `/events/{id}/reports/summary`, Entwickler-Schlüssel) gibt es nur, wenn
     alle Termine des Events im Bereich liegen.
   - **Veranstalter:** nur Events, die einem der gewählten Partner zugeordnet sind (`wpcap_partner_events`);
     „nur verknüpfte Veranstalter“ nimmt die Partner, mit denen die Person verknüpft ist (`wpcap_partner_users`).

   Ohne Eintrag gilt keine zusätzliche Einschränkung (bisheriges Verhalten). Administratoren
   (`manage_options`) sind nie eingeschränkt. Ein eingeschränkter Finanzzugang sieht Berichte, Rechnungen
   und Kassenbelege nur noch innerhalb seines Bereichs.

**Öffentliche REST-Routen** (bewusst ohne Anmeldung; Schutz durch Zugangstoken, Signatur, Rate-Limit oder nur
öffentliche Daten): Eventliste, Checkout, Checkout-Optionen und Live-Kaufzustand der Eventkarten (`/checkout/live`, A456: nur öffentliche Daten, `no-store`, je Client begrenzt), Bestellportal (`/orders/{id}` sowie
`transfer`, `resend`, `assign`, `billing`, `payout-account`, `exchange`, `refund-request` – Zugangstoken im Callback, bei `refund-request` zusätzlich der Formularnachweis, A459),
Ticketlinks anfordern (`POST /tickets/recover`, A622: immer dieselbe Antwort, 5 Anfragen je Client und Stunde, 3 Mails je Adresse und Tag; versendet nur einen einmaligen Bestätigungslink an die Inhaberadresse, neue Codes erst nach Bestätigung per POST),
Sitzplätze laufen nur über das Saalplan-Modul (das alte Sitzplan-API mit öffentlichen Holds ist seit A529/A646 entfernt), Servicezeiten und -buchungen, Rabatt-Vorschau, Warteliste (Eintragen,
Angebot annehmen/ablehnen mit Token), Event-Übersetzung lesen, Zahlungs-Webhooks (Anbietersignatur),
SMS-Statusrückmeldungen und eingehende SMS (Twilio: `X-Twilio-Signature`; generischer HTTP-Anbieter:
HMAC-SHA256 über den Rohinhalt bzw. über `{Zeitstempel}.{Rohinhalt}` mit `sms_http_webhook_secret`, ohne Schlüssel immer 403;
Zeitstempel ±5 Minuten, jede Rückmeldung wird nur einmal verarbeitet, A366), Apple-Wallet-Webservice (Authentifizierungstoken des Passes),
Scanner-App-Kopplung (`/app/pair`, einmaliger Kopplungscode) und der öffentliche Saalplan
(`/seating/occurrences/{id}`). Alle übrigen Routen haben eine Berechtigungsprüfung; der Test
`tests/integration/modules/zz_a119.php` zählt alle registrierten Routen auf und schlägt fehl, wenn eine neue
Route `__return_true` nutzt, ohne in dieser Liste zu stehen.

**Kein Zwischenspeichern von REST-Antworten (A370, A505):** Jede Antwort im Namespace `pass-captain/v1`, die
keinen eigenen `Cache-Control`-Header setzt, erhält `Cache-Control: no-store, no-cache, must-revalidate,
max-age=0, private` (`Rest\Controller::cache_headers()` an `rest_post_dispatch`) – auch für anonyme Abrufe, bei
denen WordPress selbst keine No-Cache-Header sendet. So landen weder Live-Daten (Verfügbarkeit, Kaufzustand,
Scannerergebnisse) noch tokengeschützte Antworten (Bestellportal, Servicebuchung) in Browser-, Proxy- oder
CDN-Caches. Den Bestellstatus (`/orders/{id}`, A645) gibt es per `GET` mit dem Header `X-WPCAP-Access-Token`
oder per `POST` mit `access_token` im Body; `GET …?access_token=` funktioniert übergangsweise weiter, wird aber
mit `Deprecation: true` beantwortet, weil Tokens in URLs in Server- und Proxy-Logs landen. Die
Servicebuchung liest ihr Token schon aus dem JSON-Body (Lesezugriff per `POST` mit Methoden-Override).

Die Selbstbedienung für Servicebuchungen (`GET /service-bookings/{id}`, `POST /service-bookings/{id}/confirm|rebook|cancel`, A135)
ist ohne Anmeldung erreichbar, aber nur mit dem persönlichen Buchungstoken aus der Reservierung (Prüfung im
`permission_callback` `ServiceBookingFlow::token_permission`, Hash-Vergleich in konstanter Zeit; falsche Tokens zählen
vorrangig je Client – 10 je Stunde, IPv6 je /64 – und zusätzlich kurz je Buchung – 50 in 10 Minuten –, danach 429;
Filter `wpcap_service_booking_token_limits`, A279: wer fortlaufende Buchungsnummern durchprobiert, sperrt nur
sich selbst und nicht die echten Kund:innen). Die Antworten enthalten keine Daten anderer Kund:innen.

**Objektberechtigung in allen Kanälen (A145).** `tests/integration/modules/zz_a145.php` zählt jede REST-Route,
jede admin-post-Aktion (`Admin\Menu::handlers()` samt Modulen sowie per Quelltext-Scan alle übrigen
`admin_post_*`-Registrierungen) und jede AJAX-Aktion (`wp_ajax_*`) auf und prüft für jeden authentifizierten
Einstiegspunkt:

1. **Ohne jede Capability** (angemeldet, kein Plugin-Recht) wird die Anfrage mit 403 abgewiesen – vor jeder
   Validierung oder Objektsuche. admin-post-Handler prüfen deshalb zuerst das Recht
   (`Admin\Forms::require_any_cap()` bzw. `current_user_can()`), erst danach Eingaben und Objekte.
2. **Mit allen operativen Rechten, aber ohne Zuweisung zum Event des Objekts** (eigenes Event im Team,
   ohne `manage_options`/`wpcap_view_finance`) wird jede Anfrage mit Objekt-ID – Pfad-, Query- oder
   Formularparameter, gefüllt mit IDs bestehender Objekte eines fremden Events – mit 403/404 abgewiesen oder
   scheitert an der Validierung in einem Codepfad mit objektbezogener Prüfung. Ein Filter auf ein fremdes Event
   (z. B. Berichts-Export) wird abgewiesen statt still leer geliefert.
3. **Statisch** enthält der erreichbare Code jedes Einstiegspunkts eine Rechte-/Zugriffsprüfung und – sobald er
   eine Objekt-ID liest – eine objektbezogene (`Access::can_access_event/occurrence/resource`,
   `order_in_scope`, `Scope::can_*`, Eigentum an Kassenschicht/Übergabe, Partner-Schlüssel und seine Events)
   oder ist nur für Administratoren.

Öffentliche admin-post-Aktionen sind ausschließlich die mit `admin_post_nopriv_` registrierten
(Wartelisten-Angebot annehmen/ablehnen, Austragen); sie prüfen das Token des Links. Downloads und druckbare
Seiten enden über `Http::end()` (Action `wpcap_response_end`), damit der Test sie beobachten kann.

## Entwickler-API-Schlüssel

Verwaltung unter **Pass Captain → Entwickler-API** (Bereich „Einstellungen & Integrationen“; die einzige
API-Key-Seite, N04 – die frühere Seite `admin.php?page=wpcap-api-keys` leitet dorthin weiter). Administrator:innen
(`manage_options`) sehen alle Schlüssel:
Liste mit Präfix, Bezeichnung, angelegt von, Scopes, Event, Anlage, letzter Nutzung, Ablauf, Ratenlimit und
Status; Anlegen (Bezeichnung, Event, Scopes, Ablauf, Ratenlimit), einmalige Anzeige des Schlüssels direkt nach
der Anlage (gespeichert wird nur der SHA-256-Hash), Ablauf und Ratenlimit ändern, Widerrufen. Alle Aktionen
prüfen Nonce und Recht und landen im Audit-Log (`developer_key_issued`, `developer_key_updated`,
`developer_key_revoked`, Objekt `api_key`; nur Präfix, nie der Schlüssel). Per REST legen auch Personen mit
`wpcap_manage_events` für ihr Event Schlüssel an (`POST /developer/keys`, optional `rate_limit`) und widerrufen
eigene (`POST /developer/keys/{id}/revoke`); dieselben Personen sehen auf der Seite *Entwickler-API* ohne
`manage_options` nur die Ansicht ihres Eventbereichs (eigene Schlüssel anlegen, Ratenlimit ändern, widerrufen;
Formular-Aktion `wpcap_api_key_action` mit Nonce, Recht und Event-Berechtigung).

Jede Anfrage sendet `X-WPCAP-Key: <präfix>.<geheimnis>`. Geprüft werden: Schlüssel gültig, nicht widerrufen,
nicht abgelaufen; Event der Anfrage = Event des Schlüssels; die Person, die den Schlüssel angelegt hat, ist
Administrator oder im Event-Team (`wpcap_manage_events`/`wpcap_view_reports`), existiert noch und hat das Recht
dieses Team-Eintrags weiterhin über ihre Rolle (A278: ein Team-Eintrag allein genügt nicht) und hat alle Termine
des Events in ihrem Standort-/Partnerbereich (A119); der Scope der Route ist im Schlüssel enthalten; das Ratenlimit des
Schlüssels (Standard 600 Anfragen/Minute, je Schlüssel 1–10000, Filter `wpcap_developer_key_rate_limit`) ist
nicht überschritten. Sonst lehnt WordPress die Anfrage ab (401/403); ist nur das Ratenlimit erreicht, antwortet
die API mit 429 `wpcap_rate_limited` und `Retry-After` (Sekunden bis zur nächsten Minute).

Verliert die Person eine Rolle oder wird sie gelöscht, widerruft `UserAccess` ihre Schlüssel, deren Event sie
danach nicht mehr lesen dürfte (beim Löschen alle; Audit `developer_key_revoked` mit `reason`
`owner_access_lost` bzw. `owner_deleted`, siehe „Rollenentzug und Löschen von Benutzern“).

| Route (Namensraum `pass-captain/v1`) | Scope | Inhalt |
|---|---|---|
| `GET /developer/events/{event_id}` | `events.read` | Name, Slug, Beschreibung, Status des Events |
| `GET /developer/events/{event_id}/service-slots` | `slots.read` | Alle Service-Zeitfenster mit Ressource, Zeit, Kapazität, gebuchten Plätzen und Status – ohne Kundendaten |
| `GET /events/{event_id}/reports/summary` | `reports.read` | Umsatz je Währung und Check-ins im Zeitraum (`from`, `to`, höchstens 90 Tage) |

Die Zuordnung steht in `DeveloperKeys::ROUTES`; weitere Scopes gibt es bewusst nicht: Routen mit
Personendaten (Warteliste, Gästelisten, Bestellungen) bleiben eingeloggten Personen bzw. der Partner-API mit
eigenem Schlüsselmodell vorbehalten.

## PII je Rolle

Personenbezogene Daten in Admin-Listen, Detailansichten, Exporten und REST-Antworten laufen über
`PassCaptain\Pii` (`Pii::can_view( $feld )`, `Pii::show( $feld, $wert )`, `Pii::mask_email()`). Das Profil ergibt
sich aus den Capabilities, nicht aus dem Rollennamen; wer mehrere Profile hat, sieht die Vereinigung.
Nicht freigegebene Felder werden maskiert (E-Mail `a***@e***.de`, Namen als Initialen, Antworten und
Rechnungsdaten leer) und sind auch nicht durchsuchbar.

| Profil (Capabilities) | Käufer-Name | Käufer-E-Mail | Telefon | Rechnungsdaten | Teilnehmer-Name | Teilnehmer-E-Mail | Antworten (Zusatzfelder) |
|---|---|---|---|---|---|---|---|
| **voll** – `manage_options`, `wpcap_manage_orders` oder `wpcap_manage_events` (Administration, Ticket-Administration, Veranstaltungsleitung, Vor-Ort-Kasse, Support) | ja | ja | ja | ja | ja | ja | ja |
| **Finanzen** – `wpcap_view_finance` (Finanzbuchhaltung) | ja | ja | ja | ja | nein | nein | nein |
| **Einlass** – `wpcap_checkin` (Einlasspersonal, Veranstalter) | nein | nein | nein | nein | nur mit Einstellung „Teilnehmernamen beim Scannen anzeigen“ | nein | nein |
| **Leistung** – `wpcap_service_bookings` (Leistungserbringer) | nein | ja (nur Buchungen der zugeordneten Ressourcen) | nein | nein | nein | nein | nein |
| **Berichte** – `wpcap_view_reports` | nein (nur Summen) | nein | nein | nein | nein | nein | nein |

Wirkung im Plugin:

- **Scanner** (Web, Scanner-App, Offline-Paket): nie Käuferdaten; Teilnehmername nur mit der Einstellung –
  auch für volle Profile, denn der Scan-Bildschirm ist öffentlich sichtbar.
- **Bestelllisten und Rabattcodes im eingeschränkten Zugriff (A650):** Bestellliste, Prüfliste („needs_review“) und
  Erstattungsfreigaben zeigen eingeschränkten Personen nur Bestellungen, deren Positionen **alle** in ihren Events
  (und Terminen) liegen – wie beim Öffnen einer Bestellung (`Queries::can_access_order()`); Bestellungen mit Tickets
  fremder Events (E-Mail, Summe, fremder Eventname) erscheinen dort nicht mehr (`Queries::order_scope_sql()`).
  Rabattcodes sind eventübergreifend eindeutig: Ist ein Code vergeben, lautet die Antwort neutral
  `wpcap_campaign_code_unavailable` (400), Fehlversuche werden je Person begrenzt (10 je Stunde, Filter
  `wpcap_campaign_code_conflict_limit`, danach 429) und protokolliert (Audit `campaign_code_conflict`, nur ein
  Hash des Codes), damit niemand Codes anderer Veranstalter durch Ausprobieren findet.
- **Leere Eventmengen (A638):** Prüfungen über „alle Events eines Objekts“ schlagen bei leerer Menge fehl statt
  durch: Rechnung per REST (`/orders/{id}/invoice`) für eine Bestellung ohne Ticketpositionen nur für
  Administratoren; ein Gerät ohne freigegebene Events scannt nichts und erzeugt keine Einlasskonflikte.
- **Fremde Events am Scanner (A365):** Event-Zuweisung und Gerätebindung werden vor allem anderen geprüft. Ein
  Ticket eines Events ohne Einlassrecht ergibt immer `forbidden` bzw. `wrong_event` – ohne Eventnamen und ohne
  Hinweis, ob der Termin abgesagt, das Ticket erstattet oder außerhalb des Einlasszeitraums ist. Abgelehnte Scans
  liefern Web-Scanner, App und Offline-Upload nur mit `status`, `result`, `ticket_status`, Meldung, Event- und
  Ticketname sowie den Scannerfeldern (`Checkin::CLIENT_ERROR_KEYS`) aus, nie mit internen IDs.
- **Suche am Scanner (A544):** Bestellnummer immer; Teilnehmername und E-Mails nur durchsuchbar, wenn das Profil
  sie im Klartext sehen darf; Treffer zeigen E-Mails immer maskiert und den Namen nur mit der Scanner-Einstellung
  im Klartext. Höchstens 10 Treffer in den Terminen mit Einlassrecht, 30 Suchen pro Minute, Zugriffsprotokoll.
  Eingecheckt wird ein Treffer per Ticket-ID mit denselben Regeln wie ein Scan (wie „Einchecken“ in der
  Ticketliste, A338).
- **Ticketliste im Admin:** Käufer- und Teilnehmer-E-Mail sowie Teilnehmername gemäß Matrix; Suche nur über
  sichtbare Felder.
- **Teilnehmer- und No-Show-Liste (Druck/CSV):** die volle Liste (E-Mails, Antworten) nur mit
  `wpcap_manage_events`; die Basisliste zeigt den Namen gemäß Matrix.
- **Berichte und Terminabrechnung:** ausschließlich Summen, keine Namen oder E-Mail-Adressen.
- **REST:** Wartelisten-Einträge (E-Mail gemäß Matrix), Scan-Antworten (Name gemäß Matrix und Einstellung).
- **Ausnahme Gästelisten:** Der Name des Gasts ist der Suchschlüssel an der Abendkasse und bleibt für
  Einlasspersonal sichtbar; E-Mail-Adressen der Gäste werden nicht ausgegeben.
- Partner-API-Schlüssel (Maschinenzugang) folgen den Einstellungen des Partners (`anonymous_buyer`), nicht
  dieser Matrix.

Anpassbar über `wpcap_pii_matrix`, `wpcap_pii_can_view` und `wpcap_pii_mask` (siehe [HOOKS.md](HOOKS.md)).

## Hilfscode für den Einlass

Jedes Ticket hat neben dem 64-stelligen Ticket-Code (QR) einen kurzen **Hilfscode** (8 Zeichen, z. B. `K7QM-4XWA`), der auf der Ticketseite, im Ticket-PDF, in den Ticket-Mails und auf gedruckten Tickets (`{help_code}`) unter dem QR-Code steht. Einlasspersonal tippt ihn ein, wenn der QR-Code nicht lesbar ist (Web-Scanner, Offline-Scanner-PWA online, Scanner-App-API `code`).

Entscheidung und Begründung:

- **Nur für Einlasspersonal.** Der Hilfscode wird ausschließlich von den Scan-Endpunkten angenommen (Berechtigung `wpcap_checkin` mit Event-Zuweisung bzw. gekoppeltes Scannergerät) und von der Admin-Ticketsuche (A545, nur Tickets im eigenen Zugriffsbereich; jede Suche nach einem Hilfscode zählt gegen dieselben Grenzen wie am Scanner). Ticketseite, Bestellportal, Wallet und alle öffentlichen Routen verlangen weiterhin den 64-stelligen Code. Ein Hilfscode eines fremden Events führt wie ein QR-Code zu `forbidden`/`wrong_event`.
- **Raten ist gedrosselt und unergiebig.** Alphabet ohne 0/O/1/I (32 Zeichen) → 40 Bit (≈ 1,1 Billionen Codes). Jede Eingabe zählt gegen die Grenze je Benutzer bzw. – in der Scanner-App – je gekoppeltem Gerät (30 in 10 Minuten; mehrere Geräte eines Personalkontos haben je ein eigenes Budget). Die Grenzen je Client-IP (60, IPv6 je /64) und je Website (600 in 10 Minuten) werden erst danach geprüft und nur von unbekannten Codes verbraucht (A281, A460: alle Scanner am Einlass teilen sich meist eine öffentliche Adresse; gültige Eingaben vieler Geräte sperren sich so nicht gegenseitig, Rateversuche von einer Adresse aber schon; ein einzelnes Konto kann höchstens 30 Versuche vom Website-Budget nehmen; Filter `wpcap_help_code_rate_limits`). Selbst wer die Website-Grenze dauerhaft ausschöpft (86 400 Versuche/Tag), trifft bei 100 000 gültigen Tickets mit etwa 0,8 % Wahrscheinlichkeit pro Tag irgendein Ticket – und braucht dafür ein Einlasskonto für genau dieses Event, also ohnehin die Möglichkeit, Personen einzulassen. Überschreitungen werden im Audit-Log (`help_code_rate_limited`) protokolliert.
- **Kein Klartext in der Datenbank.** Der Hilfscode wird per HMAC aus dem Ticket-Code abgeleitet; gespeichert wird nur ein HMAC (`wpcap_tickets.help_hash`, eindeutig) und ein Zähler (`help_n`) für den seltenen Fall einer Kollision. Der Schlüssel stammt aus `WPCAP_HELP_CODE_KEY` oder dem WordPress-Auth-Salt, nicht aus der Datenbank. Wer nur die Datenbank liest, kann Hilfscodes weder ablesen noch offline durchprobieren.
- **Neuer Ticket-Code = neuer Hilfscode.** Jede Code-Rotation (Neuversand, Übertragung, Neuausstellung, Zuweisung, Druck, Gästeliste) ersetzt den Hilfscode sofort (Action `wpcap_ticket_code_rotated`); der alte funktioniert nicht mehr.
- **Nachvollziehbar.** Einlässe per Hilfscode stehen im Audit-Log mit `via: help_code`; der Scanner zeigt „Per Hilfscode geprüft – Ticketinhaber ggf. mit Ausweis abgleichen“.
- **Nur online.** Die Offline-Pakete enthalten keine Hilfscodes (sie wären ohne Server-Schlüssel nicht prüfbar und sonst offline durchprobierbar). Offline weisen Web-Scanner und PWA auf QR-Code bzw. langen Code hin.
- **Bestand.** Tickets aus der Zeit vor Schema 3.8.6 erhalten ihren Hilfscode beim nächsten Aufruf der Ticketseite oder bei der nächsten Code-Rotation (nur dann ist der Ticket-Code im Klartext bekannt). Ein geänderter Auth-Salt (bzw. `WPCAP_HELP_CODE_KEY`) macht bereits ausgegebene Hilfscodes ungültig; QR-Codes bleiben gültig, neue Hilfscodes entstehen beim nächsten Seitenaufruf.

## Audit-Log: Zugriffe auf personenbezogene Daten

Alle Einträge stehen in `wpcap_audit_logs` (Wer = `user_id`, Was = `action`, Objekt = `object_type`/`object_id`, Details als JSON) und sind unter **Pass Captain → Audit-Log** (nur Administratoren, `manage_options`) nach Bereich, Aktion, Benutzer, Objekt und Datum filterbar. Sie werden mit der Audit-Frist gelöscht (Löschlauf, Schritt `audit_logs`, Standard 730 Tage, Filter `wpcap_privacy_retention_days`). Details enthalten nie Geheimnisse (Tokens, Hashes, Codes, IBAN, Schlüssel) und personenbezogene Werte nur maskiert (`e***@e***.com`, `E***`); Suchbegriffe werden ebenso maskiert.

**Ansicht (A413):** Zeiten und der Datumsfilter (Von/Bis) beziehen sich auf die Website-Zeitzone (ein Tag = 00:00–24:00 Ortszeit, auch an Tagen der Zeitumstellung; gespeichert wird weiter UTC). Aktionen erscheinen mit lesbarem Namen und darunter mit ihrem Code (für den Aktionsfilter; Namen erweiterbar über `wpcap_audit_action_labels`), Bestellungen, Tickets, Events, Termine (→ Event) und Benutzer sind verlinkt.

| Bereich | Aktion | Wann |
|---|---|---|
| Ansicht | `pii_viewed` | Detailansicht mit Personendaten geöffnet: Bestellung (`order_detail`), Gästeliste eines Events (`guestlist`), Warteliste (`waitlist`), Umtausch eines Tickets (`exchange_ticket`), Kontingente eines Termins (`allocation_occurrence`), Veranstalter (`partner_detail`), Kassenbeleg-Suche (`pos_sale`). `details.view` nennt die Ansicht. |
| Ansicht | `pii_list_viewed` | Liste mit Personendaten, **einmal je Seitenaufruf und Filter**, nicht je Zeile: Bestellungen (`order_list`), Tickets (`ticket_list`), Check-ins (`checkin_list`), Erstattungen (`refund_queue`), Gästelisten-Einlass (`guestlist_checkin`), Umtausche (`exchange_list`), Einlass-Konflikte (`admission_conflicts`), Buchungen der Service-Zeitfenster (`service_bookings`), Veranstalter (`partner_list`), Audit-Log selbst (`audit_log`). `details.filters` und `details.rows`. |
| Ansicht | `pii_api_read` | Erfolgreicher GET auf eine REST-Route mit Personendaten: Warteliste (`waitlist_entries`), Gästelisten-Suche/Abholliste (`guestlist_search`, `guestlist_will_call`), Offline-Paket (`offline_package`), Partner-API Bestellungen/Tickets/Check-ins (`partner_*`, mit `key_id`). Erweiterbar über `wpcap_pii_audit_rest_routes`. |
| Export | `pii_exported` | Jede Datei/Druckansicht (ohne Entprellung), `details.kind`: `guestlist_csv`, `guestlist_print`, `invoice_pdf`, `invoice_html`, `pos_receipt`, `pos_tickets_pdf`, `zreport_html`, `zreport_csv`, `partner_settlement_csv`/`_html`, `partner_statement_csv`. |
| Export | bestehend | `attendee_list_csv`/`_html`, `no_show_list_csv`/`_html`, `print_batch`, `print_batch_created`/`_delivered`/`_cancelled`/`_failed`, `report_exported`, `accounting_exported`, `settlement_sheet_csv`/`_html`. |
| Datenschutz | `privacy_export_run`, `privacy_erasure_run` | Je Seite eines Plugin-Exporters/-Löschers der WordPress-Datenschutzwerkzeuge (`object_type` `privacy_request`, `object_id` = Anfrage-ID; Exporter/Löscher-Schlüssel, Seite, Anzahl bzw. entfernt/behalten, maskierte E-Mail). Erste Seite immer, weitere nur mit Daten. |
| Datenschutz | `privacy_export_completed`, `privacy_erasure_completed` | WordPress hat die Exportdatei erstellt (`wp_privacy_personal_data_exported`) bzw. die Löschanfrage abgeschlossen (`wp_privacy_personal_data_erased`). Bestehend: `privacy_erasure` je anonymisierter Bestellung, `privacy_erasure_deferred`, `privacy_retention`. |
| Rollen | `user_role_set`, `user_role_added`, `user_role_removed` | WordPress-Rollenänderung (`set_user_role`, `add_user_role`, `remove_user_role`) mit einer Plugin-Rolle oder einer Rolle mit `wpcap_*`-Rechten (z. B. Administrator); `object_type` `user`, Handelnde Person = `user_id`. |
| Rollen | `user_cap_added`, `user_cap_removed` | Einzelrecht `wpcap_*` direkt am Benutzer vergeben/entzogen (z. B. `wpcap_checkin` durch die Teamzuordnung eines Events). Bestehend: `staff_assigned`/`staff_removed` (Event-Team), `admission_area_staff_added`/`admission_area_staff_removed`, `partner_users_set`, `pos_cashier_added`. |
| Korrekturen | `record_corrected` | Personal hat Personendaten korrigiert, `details.changes`: Feldname → maskiertes `from`/`to`. Quellen: Teilnehmerkorrektur im Ticket-Admin (`object_type` `ticket`, mit Grund; der Ticketcode bleibt), neue E-Mail beim erneuten Senden einer Gästelisten-Freikarte (`guest_entry`). `billing_updated` (Bestellportal) enthält dieselbe maskierte Änderungsliste. Bestehend: `ticket_holder_changed`, `ticket_assigned`, `ticket_reissued`. |

**Entprellung:** Ansichten und REST-Lesezugriffe derselben Person auf dasselbe Objekt mit derselben Ansicht und denselben Filtern werden innerhalb von 10 Minuten nur einmal protokolliert (Filter `wpcap_pii_audit_window`, `0` = jeden Aufruf). Geprüft wird direkt im Audit-Log über einen Fingerabdruck (`details.fp`), ohne zusätzliche Optionen oder Transients. Exporte, Datenschutzläufe, Rollen und Korrekturen werden immer protokolliert.

**Nicht protokolliert:** einzelne Scans am Einlass (stehen als Check-in in `wpcap_checkins`), Käuferzugriffe über den eigenen Bestell- oder Ticketlink (betreffen die eigenen Daten).

## Fehlerausgaben ohne Secrets (Redaction)

Fehlertexte von Zahlungs-, SMS-, Fiskal- und Wallet-Anbietern, Transportfehler ausgehender HTTP-Aufrufe und Datenbankfehler können Zugangsdaten oder Kundendaten wiederholen (z. B. „Invalid API Key provided: sk_live_…“, eine URL mit `?api_key=…`, ein `Authorization`-Header). Alle Ausgaben für Betrieb und Clients laufen deshalb durch einen zentralen Filter (`PassCaptain\Redact`):

| Ausgabe | Behandlung |
|---|---|
| Fehlertexte der Anbieter-Clients (Stripe, PayPal, Mollie, Twilio, HTTP-SMS) | beim Erzeugen bereinigt (`Redact::text()`), E-Mail-Adressen maskiert |
| REST-Fehlerantworten aller `pass-captain/v1`-Routen (Meldung und `data`) | Filter `rest_request_after_callbacks` |
| Unbehandelte Ausnahmen in REST-Callbacks und Berechtigungsprüfungen | `rest_endpoints` umhüllt die Callbacks: Antwort 500 `wpcap_internal_error` mit Referenz, ohne Meldung, Klasse oder Stack-Trace; ins Support-Protokoll und ins PHP-Fehlerprotokoll (`debug.log`) nur „Klasse: bereinigte Meldung (Datei:Zeile)“ mit derselben Referenz |
| Zahlungsstart-Fehler der Anbieter (Checkout, Wartelisten-Angebot, Umtausch, A324) | Käufer:innen sehen nur einen neutralen Text mit Fehler-ID; der (bereinigte) Anbietertext steht im Support-Protokoll |
| Support-Protokoll (`wpcap_logs`, A327) | Meldung und Kontext bereinigt, E-Mail-Adressen maskiert; Kontextschlüssel für Ticketcodes, Zugangslinks, Tokens, Namen, Adressen und Telefonnummern werden verworfen; Rotation nach 30 Tagen, Löschung mit der Bestellung |
| Audit-Details | Werte von Schlüsseln wie `secret`, `password`, `token`, `api_key`, `signature`, `iban`, `card_number` werden entfernt, übrige Texte bereinigt |
| Fehlerspalten: Jobs (`last_error`, auch bei abgestürztem Handler → `dead_letter` statt hängender Sperre), SMS, Webhook-Zustellungen, Erstattungen; Mail-Fehlerprotokoll, Wallet-Protokoll, Schema-Fehler | bereinigt und gekürzt |
| Admin-Hinweise (Fehler), Bestellnotizen in WooCommerce, Admin-Mail bei fehlgeschlagener Gutschein-Rückbuchung | bereinigt (E-Mail-Adressen bleiben für das Personal sichtbar) |
| Website-Zustand und Betriebsübersicht | zeigen nur bereinigte Fehlertexte; Zugangsdaten nur mit ihrer Quelle |

Entfernt werden: die Werte aller Einstellungen aus `Secrets::KEYS` (auch aus Konstanten/Umgebung, im Klartext, URL- und JSON-kodiert, bei PEM-/JSON-Werten auch einzelne Schlüsselzeilen und `private_key`), die Signaturgeheimnisse ausgehender Webhooks, PEM-Privatschlüssel, `sk_`/`rk_`/`whsec_`-Schlüssel, `Bearer`/`Basic`-Zugangsdaten, `Name=Wert`-Paare mit geheimen Namen (`api_key=`, `token=`, `password:` …), `benutzer:passwort@` in URLs; Kartennummern (13–19 Ziffern mit Luhn-Prüfsumme) und IBANs (Prüfsumme) bleiben nur mit den letzten vier Zeichen stehen. Weitere Werte lassen sich mit dem Filter `wpcap_redact_values` ergänzen. Werte unter 8 Zeichen werden nicht gesucht (Verwechslung mit gewöhnlichen Wörtern).

**Eingabeprüfung:** Öffentliche REST-Eingaben (Checkout, Check-in, Warteliste, Partner-API) mit falschem Typ (Liste statt Text, `true` als Anzahl), negativen oder gebrochenen Zahlen, Überlänge oder Unicode-Sonderfällen enden mit einer 4xx-Antwort ohne PHP-Warnung; Anzahlen und Ticketart-IDs werden streng als positive Ganzzahl gelesen (`-2` wird nicht mehr zu `2`). Getestet in `tests/integration/modules/zz_a194.php` (Canary-Werte für alle Secrets, erzwungene Fehler, Fuzzing).

**E-Mail-Felder (A492, A493):** Jede E-Mail-Eingabe (Käufer:in und Teilnehmende im Checkout, Übertragung, Teilnehmerzuweisung, Warteliste, Servicebuchung, Partner-API, Kasse, Gästeliste, Gutschein-Brücke) läuft über `Http::email()`: eine Liste, ein Objekt oder `null` wird zu `''` (kein `TypeError` aus `sanitize_email()`); die REST-Route `/checkout` prüft `email` zusätzlich als Text, bevor sie bereinigt. Alle E-Mail-Spalten sind `varchar(190)`: eine gültige, aber längere Adresse wird beim Eingang mit 400 (`reason: email_too_long`) abgelehnt – vorher entstand eine bezahlte Bestellung ohne Tickets bzw. ein endloses „Gerade ist sehr viel los“ (503). Teilnehmerdaten älterer Bestellungen, die nicht in die Spalten passen, blockieren die Ausstellung nicht mehr (E-Mail entfällt, Name auf 190 Zeichen gekürzt). Ein Datenbankfehler „Wert passt nicht in die Spalte“ im Checkout ist ein endgültiger 400er (`reason: invalid_data`, Fehler-ID im Support-Protokoll), kein 503 mit Wiederholung. Getestet in `tests/integration/modules/zz_a492_a493.php`. Optionale E-Mail-Felder (Teilnehmerzuweisung, Korrektur im Admin, Leistungserbringer, Veranstalter-Kontakt, Gästeliste, Kasse, WooCommerce-Teilnehmende, Teilnehmerfelder vom Typ E-Mail) laufen über `Http::optional_email()` (A498): WordPress' `sanitize_email()` macht aus „kein-mail“ ein leeres Feld – eine eingegebene, aber ungültige Adresse wird deshalb abgelehnt statt still als „keine Angabe“ übernommen.

**Textlängen (A494):** Freitext wird vor dem Speichern mit `Text::limit()` auf die Spaltenbreite in Zeichen gekürzt (`varchar(N)` zählt Zeichen), nie per `substr()` in Bytes: ein Schnitt mitten in „é“ ergab ungültiges UTF-8, das die Datenbank bei jedem Versuch ablehnte (Erstattungsgrund und Stornierungsanfrage im Bestellportal, Ablehnungsnotiz, Fehlertexte, Zahlungsreferenzen, Veranstalter-Stammdaten, Partner-Storno, Abrechnungszeilen). Ungültige Bytefolgen im Text werden dabei ersetzt. Getestet in `tests/integration/modules/zz_a494.php`.

**CSV-Exporte (A495):** Alle CSV-Dateien (Berichte, Teilnehmer- und No-Show-Listen, Gästelisten, Einwilligungen, Kassen-Z-Bericht, Veranstalter- und Terminabrechnungen, Buchhaltungs- und DATEV-Export) entstehen in `Reports\Scope::csv()`: jede Zelle, die mit `=`, `+`, `-`, `@`, Tab oder Wagenrücklauf beginnt (auch nach Leerzeichen), bekommt ein führendes `'`; reine Zahlen bleiben unverändert. Angeführt wird nur nach RFC 4180 (verdoppelte Anführungszeichen, kein Backslash-Escape) – sonst schloss ein Name wie `x\";=1+1;\"` das Feld in Excel/LibreOffice vorzeitig und `=1+1` wurde zur eigenen Formelzelle. Getestet in `tests/integration/modules/zz_a495.php`.

**Teilnehmerzuweisung im Bestellportal (A635):** Die öffentliche Route `POST /orders/{id}/assign` hat ein `args`-Schema (Texttypen, `attendee_name` und `attendee_email` höchstens 190 Zeichen, Token 64, Ticket-ID 32, `answers` als Objekt) und ein Rate-Limit wie Neuversand und Rechnungsdaten: 30 Anfragen je Client und Stunde, 20 je Bestellung und Stunde (nur mit gültigem Bestelllink gezählt, Filter `wpcap_portal_assign_rate_limit`). Der Name, der in die Mail an die neue Person geht, wird auf 190 Zeichen gekürzt; Namen mit Web-Adresse (`https://…`, `www.…`, `domain.tld/…`) werden mit 400 (`name_link`) abgelehnt (Filter `wpcap_attendee_name_has_link`). Getestet in `tests/integration/modules/zz_a635.php`.
## Inline-JavaScript und Content-Security-Policy (A302)

Das Plugin gibt kein handgeschriebenes `<script>`/`<style>` mehr aus. Admin-Seiten hängen Inline-Code an
registrierte Handles (`wp_add_inline_script()`/`wp_add_inline_style()` über `PassCaptain\Assets`; Menü-CSS/-JS
über `admin_enqueue_scripts`, Kassen-Konfiguration vor `wpcap-pos`, Erstattungsformular und Mail-Vorschau im
Footer). Eigenständige Seiten ohne Theme (Bestellportal, Umtauschformular, Wartelisten-Angebot) drucken ihre Skripte
mit `wp_get_inline_script_tag()`; ein CSP-Plugin kann so über den Filter `wp_inline_script_attributes` eine Nonce
setzen. Weiterhin inline sind die `<style>`-Blöcke dieser eigenständigen Dokumente und der Druckansichten (eigene
HTML-Dokumente ohne `wp_head`) sowie `onclick="window.print()"` der Druckknöpfe; eine strikte CSP ohne
`'unsafe-inline'` für Styles/Handler braucht dort eine Ausnahme.

## Offenlegung

Betroffen sind alle Anbieter-Geheimnisse: Stripe Secret Key und Webhook-Signing-Secret, PayPal Client-Secret, Mollie API-Key, Twilio Auth Token, Auth-Header des generischen SMS-Anbieters, Apple-Wallet-Zertifikat/Schlüssel/Passwort/WWDR, Google-Wallet-Servicekonto sowie die Signing-Secrets ausgehender Webhooks (`Settings::SECRET_KEYS`, erweiterbar über den Filter `wpcap_secret_settings`).

**Reihenfolge der Quellen** (unverändert): Konstante `WPCAP_<KEY>` in `wp-config.php` → Umgebungsvariable `WPCAP_<KEY>` → in der Datenbank gespeicherter Wert. Konstanten bleiben die empfohlene Variante; sie werden nie in der Datenbank gespeichert.

Vor 1.0 müssen Bedrohungsmodell, Rollen-/Nonce-Prüfung, SQL-Transaktionen, Datenschutzexport und -löschung, Offline-Konflikte, Abhängigkeitsprüfung und Wiederherstellung extern oder durch eine dokumentierte unabhängige Prüfung bewertet werden. Dieses Dokument ist keine Sicherheitszertifizierung.

## Keine Code-Rotation durch Käufer während des Einlasses (A593)

Selbstbedienungs-Aktionen über den Bestelllink, die einen neuen Ticketcode erzeugen – „Tickets erneut
senden“ im Portal, Ticket-PDF ohne bekannten Code, Übertragung und Teilnehmerwechsel –, werden
abgelehnt (`wpcap_ticket_rotation_locked`, HTTP 409), sobald

- der Einlass des Termins begonnen hat: frühester Zeitpunkt aus Einlasszeit des Termins
  (`doors_open_at`), Beginn minus `entry_opens_before_minutes` und – bei Tickettypen mit Einlassregel –
  Beginn minus deren „Einlass ab … Minuten vor Beginn“, oder
- der aktuelle Code in einem Offline-Paket steckt, das ein zugewiesenes Gerät in den letzten 72 Stunden
  abgerufen hat (Grund `offline_package`).

Sonst entstünden „offline gültig, online ungültig“-Tickets: Offline-Geräte lassen den alten Code ein, der
Server kennt nur den neuen, und an der Tür kann der Gast jeden der beiden vorzeigen. „Erneut senden“ und
„PDF (alle)“ lassen gesperrte Tickets aus und melden den Fehler nur, wenn keines übrig bleibt.
Personal-Aktionen (Neu ausstellen, Erneut senden im Admin, Drucken) sind nicht betroffen. Der Filter
`wpcap_self_service_rotation_lock` kann die Sperre je Ticket ändern.
