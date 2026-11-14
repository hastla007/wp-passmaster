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

**Auszahlungsdaten der Veranstalter.** `wpcap_partners.payout_details` (IBAN, BIC, Kontoinhaber) wird mit demselben Vault verschlüsselt, Kontext `partner_payout:<partner_id>` (an die Zeile gebunden: in eine andere Zeile kopiert, lässt sich der Wert nicht entschlüsseln). Der Partner-Schnappschuss einer abgeschlossenen Abrechnung enthält die Angaben ebenfalls nur verschlüsselt (Kontext `partner_settlement_payout:<settlement_id>`). Bestehende Klartextwerte (Tabelle und Schnappschüsse) verschlüsselt `Vault::migrate()` einmalig beim Update (Vault-Version 2, Schema 3.10.0; idempotent, auch beim Schlüsselwechsel). Anzeige im Admin nur gekürzt („•••• 3000“, letzte 4 Zeichen der IBAN); ein leeres Feld behält den Wert. **Entscheidung Export:** vollständig nur im CSV-Export der Veranstalter-Abrechnung und nur für Finanzen/Admins (`Settlements::can_manage()`), weil der Betreiber damit die Überweisung ausführt; verknüpfte Veranstalter-Benutzer erhalten dort die gekürzte Form. Druckbeleg, Partner-REST-API, Berichte und sonstige Exporte enthalten die Angaben nie. Bei Schlüsselverlust zeigt das Formular „kann nicht entschlüsselt werden“ und bittet um Neueingabe.

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
