# Sicherheitsmodell und Meldungen

## Schutzgrenzen

- Ticket-, Gastbestellungs- und API-Schlüssel werden als zufällige Geheimnisse ausgegeben; in der Datenbank werden nur Hashes gespeichert. Gastlinks sind Inhaberzugänge und dürfen nicht weitergegeben werden.
- WordPress REST-Schreibzugriffe prüfen Berechtigungen und Eventbereiche. Entwickler-API-Schlüssel sind auf ein Event sowie `events.read` und `reports.read` beschränkt und können ablaufen oder widerrufen werden.
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

**Anzeige.** Admin-Seiten geben Secrets nie aus: angezeigt wird nur „gespeichert ••••1234“ (die letzten vier Zeichen ab 16 Zeichen Länge, sonst nur „••••“), ein leeres Ersetzen-Feld und „Entfernen“. Webhook-Signing-Secrets werden nach dem Anlegen bzw. Erneuern genau einmal angezeigt (bis dahin verschlüsselt zwischengespeichert), danach nur maskiert.

## Redaktion (Logs, Audit, Fehler)

`Security\Redact` ist die zentrale Stelle:

- `Audit::record()` speichert Details nur redigiert: Werte unter Schlüsseln wie `secret`, `token`, `password`, `api_key`, `authorization`, `private_key`, `signature`, `iban` werden entfernt, E-Mail-/Telefonfelder maskiert (`j***@example.com`).
- Freitexte (Anbieterantworten, DB-Fehler, WP_Error-Meldungen) werden von konfigurierten Secrets (exakter Wert), Stripe-/Mollie-/Twilio-/PayPal-Tokens, JWTs, Partner- und Entwickler-Keys, `whsec_…`, PEM-Private-Keys, 64-stelligen Ticket-/Zugangscodes (gekürzt), Token-Query-Parametern und E-Mail-Adressen befreit.
- Fehlermeldungen von Stripe, PayPal, Mollie und Twilio werden vor der Weitergabe (REST-Antwort, Bestellnotiz, Audit) redigiert.
- Das Plugin schreibt nur über `Redact::log()` ins PHP-Error-Log (DB-Fehler können Zeilenwerte zitieren). Filter `wpcap_error_log` (false = nicht schreiben).

## Ausgehende Verbindungen (SSRF)

Für vom Admin konfigurierbare Ziele – Webhooks, generischer SMS-Anbieter, Fiskaldienste – gilt `Security\OutboundHttp`:

- nur `https`; keine Zugangsdaten in der URL; nicht-kanonische IP-Schreibweisen (`0x7f.1`, `2130706433`) werden abgelehnt;
- der Host wird aufgelöst, **jede** Adresse muss öffentlich sein: Loopback, RFC 1918, CGNAT (100.64/10), Link-Local inkl. Cloud-Metadaten (169.254.169.254), ULA (fc00::/7), fe80::/10, Multicast, reservierte und Dokumentationsnetze, IPv4-mapped und NAT64-IPv6 sowie `localhost`, `*.local`, `*.internal` sind gesperrt;
- die Prüfung läuft beim Speichern **und** vor jedem Versand (DNS-Rebinding nach dem Anlegen wird erkannt); zusätzlich `wp_safe_remote_*`;
- keine Weiterleitungen (3xx = Fehlversuch), Timeout max. 15 s, Antwortgröße begrenzt (Webhooks/SMS 64 KB, Fiskal 512 KB), optionale JSON-Prüfung;
- Fehlermeldungen enthalten weder URL-Parameter noch Header oder Bodies.
- Lokale Fiskaldienste (z. B. efsta EFR unter `http://127.0.0.1:5618`) sind nur erreichbar, wenn „Lokalen Fiskaldienst erlauben“ (`fiscal_allow_local_service`, Filter `wpcap_fiscal_allow_local_service`) aktiv ist – und auch dann nur Loopback/private Adressen, nie Klartext-HTTP ins Internet.
- APNs: das Pass-Type-Zertifikat wird nur `api.push.apple.com`/`api.sandbox.push.apple.com` vorgelegt (nur HTTPS, keine Redirects). Google Wallet: der Token-Endpunkt ist fest (`https://oauth2.googleapis.com/token`, Filter `wpcap_wallet_google_token_uris`); ein abweichendes `token_uri` im Servicekonto-JSON wird ignoriert.
- Zahlungsanbieter (Stripe, PayPal, Mollie, Twilio) verwenden feste Anbieter-Hosts.

Restrisiko: Zwischen Prüfung und Verbindungsaufbau löst WordPress den Namen erneut auf; ein Angreifer mit TTL-0-DNS könnte theoretisch umschalten. Für Hochrisiko-Umgebungen ausgehenden Traffic zusätzlich per Egress-Firewall/Proxy beschränken.

## Rate-Limits und Client-Adresse

Die Plugin-Limits (Checkout pro IP und E-Mail, Servicebuchungen, Warteliste, Sitzplatz-Holds, Partner-API, Kopplung, Portal) liegen in WordPress-Transients. Sie sind **pro Site** und **kein verteilter Schutz**: Ohne persistenten Object-Cache landen sie in der Optionstabelle; mehrere PHP-Worker/Server können sich überholen (Lesen-dann-Schreiben ohne atomares Inkrement), und hinter Proxy/CDN sind sie nur so gut wie die erkannte Client-Adresse. Empfehlung: Rate-Limiting und Bot-Schutz zusätzlich am WAF/CDN bzw. Reverse Proxy (z. B. für `/wp-json/wp-pass-captain/v1/checkout`, `/…/app/pair`, `/…/partner/*`).

**Client-Adresse** (`RateLimit::client_ip()`, verwendet von allen Limits):

- Standard: `REMOTE_ADDR`. `X-Forwarded-For` & Co. werden **ignoriert**, weil jeder Client sie setzen kann.
- Hinter Reverse Proxy/CDN: unter *Einstellungen → Sicherheit* die Proxy-Adressen/CIDR-Bereiche eintragen (`trusted_proxies`, auch Konstante `WPCAP_TRUSTED_PROXIES` oder Filter `wpcap_trusted_proxies`) und den Header wählen (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`, `True-Client-IP`).
- Der Header wird nur ausgewertet, wenn die Anfrage tatsächlich von einem vertrauenswürdigen Proxy kommt. Bei `X-Forwarded-For` gilt der rechteste Eintrag, der selbst kein vertrauenswürdiger Proxy ist (links stehende Einträge sind fälschbar). Ungültige Werte fallen auf `REMOTE_ADDR` zurück.
- Der Filter `wpcap_client_ip` kann das Ergebnis weiterhin ersetzen.

## Berechtigungen (automatisch geprüft)

`tests/integration/modules/zz_w5_permissions.php` enthält eine Tabelle aller REST-Routen und aller admin-post/admin-ajax-Aktionen. Jede Route ist als `public` (mit Begründung), `token` (Handler verlangt Geheimnis/Signatur), Capability, Gerät, Entwickler- oder Partner-Key deklariert. Der Test schlägt fehl, wenn eine Route fehlt oder wenn Gast, Abonnent, Mitarbeitende eines anderen Events, widerrufene/abgelaufene/fremde Entwickler-Keys, widerrufene Geräte oder Partner-Keys eines anderen Veranstalters Erfolg haben oder Daten ändern.

## Offenlegung

Sicherheitsprobleme bitte über GitHubs private Meldemöglichkeit des Repositorys melden, sofern sie aktiviert ist. Keine Ticketcodes, Kundendaten, API-Schlüssel oder funktionsfähigen Exploits in öffentlichen Issues posten. Eine unabhängige Prüfung und ein formeller Incident-Prozess stehen noch aus.

## Release-Gate

Vor 1.0 müssen Bedrohungsmodell, Rollen-/Nonce-Prüfung, SQL-Transaktionen, Datenschutzexport und -löschung, Offline-Konflikte, Abhängigkeitsprüfung und Wiederherstellung extern oder durch eine dokumentierte unabhängige Prüfung bewertet werden. Dieses Dokument ist keine Sicherheitszertifizierung.
