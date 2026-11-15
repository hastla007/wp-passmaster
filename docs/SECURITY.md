# Sicherheitsmodell und Meldungen

## Schutzgrenzen

- Ticket-, Gastbestellungs- und API-Schlüssel werden als zufällige Geheimnisse ausgegeben; in der Datenbank werden nur Hashes gespeichert. Gastlinks sind Inhaberzugänge und dürfen nicht weitergegeben werden.
- WordPress REST-Schreibzugriffe prüfen Berechtigungen und Eventbereiche – auch für Objekt-IDs im Body (Ticketart, Ressource, Pass, Buchung, Key). Entwickler-API-Schlüssel sind auf ein Event sowie `events.read` und `reports.read` (plus Add-on-Scopes) beschränkt und können ablaufen oder widerrufen werden. Ein Key gilt nur, solange die Inhaberin die passende Capability über ihre Rolle **und** als Eventpersonal hat; beim Löschen/Entfernen der Person werden ihre Keys widerrufen und ihre Zuweisungen entfernt, beim Rollenwechsel die nicht mehr nutzbaren Keys widerrufen. Ratenlimits über 6.000 Anfragen/Minute je Key (bis 100.000) kann nur ein Administrator setzen.
- Die Checkout-Antwort enthält weder die fortlaufende `order_id` noch die `public_id` (Teil des Portal-Links); der Browser bekommt nur die kurze Bestellnummer (`reference`). Zugangslink und volle ID gehen nur per E-Mail.
- Öffentliche Checkout-, Sitzplatz- und Wartelistenrouten sind nicht authentifiziert. Sie benötigen Hosting-seitige Rate-Limits, TLS, Bot-Abwehr und Überwachung (siehe „Rate-Limits“).
- Offline-Scanner speichern Codes lokal und können während einer Netzunterbrechung nicht garantieren, dass ein Ticket an einem anderen Gerät unbenutzt geblieben ist.
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
- der Host wird aufgelöst, **jede** Adresse muss öffentlich sein: Loopback, RFC 1918, CGNAT (100.64/10), Link-Local inkl. Cloud-Metadaten (169.254.169.254), ULA (fc00::/7), fe80::/10, Multicast, reservierte und Dokumentationsnetze, IPv4-mapped und NAT64-IPv6 sowie `localhost`, `*.local`, `*.internal` sind gesperrt;
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

## CSV-Exporte

Alle CSV-Exporte laufen über `Reports\Scope::csv()`: RFC-4180-Quoting ohne Backslash-Escape (ein `\"` im Text kann die Zelle nicht beenden) und `csv_cell()`: führende `= + - @` (auch nach Leerzeichen) sowie solche Zeichen direkt nach `"`, `;` oder Tab im Text werden mit `'` entschärft; gültige JSON-Dokumente (Archivspalten) bleiben unverändert.

## Rate-Limits und Client-Adresse

Die Plugin-Limits (Checkout pro IP und E-Mail, Servicebuchungen, Warteliste, Sitzplatz-Holds, Partner-API, Kopplung, Portal) liegen in WordPress-Transients. Sie sind **pro Site** und **kein verteilter Schutz**: Ohne persistenten Object-Cache landen sie in der Optionstabelle; mehrere PHP-Worker/Server können sich überholen (Lesen-dann-Schreiben ohne atomares Inkrement). Mit persistentem Object-Cache zählen `RateLimit::hit()`/`RateLimit::allow()` und das Checkout-Limit atomar (`wp_cache_incr()` in der Transient-Gruppe). Ohne Object-Cache bleibt es bei Best-Effort, und hinter Proxy/CDN sind sie nur so gut wie die erkannte Client-Adresse. Empfehlung: Rate-Limiting und Bot-Schutz zusätzlich am WAF/CDN bzw. Reverse Proxy (z. B. für `/wp-json/wp-pass-captain/v1/checkout`, `/…/app/pair`, `/…/partner/*`).

Warteliste (Event): 5 Anmeldungen je Adresse (Groß-/Kleinschreibung egal) und Event, 20 je IP und Event und 60 je IP über alle Events pro Stunde (Filter `wp_pass_captain_waitlist_ip_limit_v1`). Es gibt kein Double-Opt-in; die Antwort verrät nicht, ob eine Adresse schon eingetragen ist, und jede Eintragungsmail enthält einen Austragen-Link.

**IPv6:** Sitzplatz-Holds und Wartelisten zählen IPv6-Adressen je /64-Netz (`RateLimit::client_network()`), damit wechselnde Adressen innerhalb eines Anschlusses das Limit nicht zurücksetzen.

**Client-Adresse** (`RateLimit::client_ip()`, verwendet von allen Limits):

- Standard: `REMOTE_ADDR`. `X-Forwarded-For` & Co. werden **ignoriert**, weil jeder Client sie setzen kann.
- Hinter Reverse Proxy/CDN: unter *Einstellungen → Sicherheit* die Proxy-Adressen/CIDR-Bereiche eintragen (`trusted_proxies`, auch Konstante `WPCAP_TRUSTED_PROXIES` oder Filter `wpcap_trusted_proxies`) und den Header wählen (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`, `True-Client-IP`).
- Der Header wird nur ausgewertet, wenn die Anfrage tatsächlich von einem vertrauenswürdigen Proxy kommt. Bei `X-Forwarded-For` gilt der rechteste Eintrag, der selbst kein vertrauenswürdiger Proxy ist (links stehende Einträge sind fälschbar). Ungültige Werte fallen auf `REMOTE_ADDR` zurück.
- Der Filter `wpcap_client_ip` kann das Ergebnis weiterhin ersetzen.

## Rollen, Eventscoping und Datenminimierung

- **Eventscoping:** Operative Rollen brauchen eine Zuweisung je Event (`wpcap_event_staff`) oder – nur für Einlass – je Termin (`wpcap_occurrence_staff`, Abschnitt „Einlasspersonal nur für diesen Termin“ im Termin-Admin). Terminpersonal darf an genau diesem Termin ein-/auschecken, den Scanner/die App/Offline-Pakete dieses Termins nutzen und die Basis-Teilnehmerliste sehen; es erhält nie eventweiten Zugriff (`Access::can_access_occurrence`).
- **Datenminimierung je Rolle** (`Security\DataPolicy`, abgeleitet aus den Capabilities):
  - Einlass (`wpcap_checkin`) und Veranstalter/Partner (`wpcap_view_reports` ohne Bestell-/Finanzrechte) erhalten keine Zahlungsdaten: keine Beträge, keine Anbieter-Referenzen, keine Rechnungsdaten – weder im Admin (Berichte zeigen nur Ticket- und Einlasszahlen), noch im REST-Bericht `events/{id}/reports/summary` (Entwickler-Keys behalten den vollen Bericht), noch im Bericht-CSV.
  - Finanzbuchhaltung sieht Zahlungsdaten, aber keine Teilnehmer-Zusatzangaben (Antworten, Teilnehmer-E-Mails) und keine Teilnehmerlisten.
  - Support (nur `wpcap_manage_orders`) sieht Käufer-/Teilnehmerdaten maskiert (`b•••@e•••.com`, `E. M•••`, keine Rechnungsdaten/Antworten). Pro Bestellung kann eine Freigabe mit Grund angefordert werden (15 Minuten, Audit `pii_unmasked`). Abschaltbar mit `support_mask_pii`.
- **Gemeinsame Konten:** Organisatorische Regel: jede Person hat ein eigenes Konto; Konten werden nicht weitergegeben, Scanner-Geräte werden über kurzlebige Gerätekopplungen statt geteilter Logins betrieben. Technisch: Anmeldungen von Einlass-/Kassen-/Partnerkonten von einem weiteren Gerät/Netz werden erkannt und protokolliert (`concurrent_session_detected`, nur gekürzte Fingerabdrücke, keine IP-Adressen); mit `staff_single_session` werden andere Sitzungen beim Login beendet. Die Seite „Supportzugriff“ listet Konten mit gleichzeitigen Sitzungen.
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
