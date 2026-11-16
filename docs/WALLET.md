# Wallet (Apple Wallet und Google Wallet)

Das Modul `PassCaptain\Modules\Wallet` erzeugt für neu ausgestellte Tickets einen Apple-Wallet-Pass (`.pkpass`, Stil *eventTicket*) und/oder einen „In Google Wallet speichern“-Link und hält beide aktuell, wenn ein Ticket widerrufen, ein Termin verschoben oder abgesagt oder ein Ticketcode erneuert wird.

Implementierung: `src/WalletPasses/` (Provider werden über `PassCaptain\Wallet\Bridge` / Filter `wp_pass_captain_wallet_provider_v1` registriert und können dort ersetzt werden). Admin: *Pass Captain → Wallet*.

## Einrichtung

### Apple Wallet

1. Im Apple Developer Account eine **Pass Type ID** (`pass.com.example.tickets`) anlegen und das Zertifikat erzeugen.
2. Zertifikat und privaten Schlüssel als PEM (oder als `.p12` mit Passwort) sowie das **Apple WWDR-Zwischenzertifikat** (G4) bereitstellen – bevorzugt in `wp-config.php`:

```php
define( 'WPCAP_APPLE_PASS_CERT', '/pfad/ausserhalb/webroot/pass.pem' ); // oder .p12, oder PEM-Inhalt
define( 'WPCAP_APPLE_PASS_KEY', '/pfad/ausserhalb/webroot/pass.key' );  // entfällt bei .p12
define( 'WPCAP_APPLE_PASS_KEY_PASSWORD', '…' );
define( 'WPCAP_APPLE_WWDR_CERT', '/pfad/AppleWWDRCAG4.pem' );
```

   Alternativ Umgebungsvariablen gleichen Namens oder die Felder auf der Wallet-Seite (dann verschlüsselt in der Option `wpcap_secrets` gespeichert, siehe docs/OPERATIONS.md „Zugangsdaten / Secrets“).
3. Auf der Wallet-Seite Pass Type ID, Team-ID (10 Zeichen) eintragen und „Apple Wallet aktiv“ setzen. Die Statusübersicht zeigt Gültigkeit von Pass- und WWDR-Zertifikat, ob Schlüssel und Zertifikat zusammenpassen und ob die Pass Type ID zum Zertifikat gehört. „Test-Pass herunterladen“ erzeugt einen signierten Beispielpass.
4. Versand: als `.pkpass`-Anhang (Standard) oder als signierter Download-Link.
5. Ablauf der Zertifikate (A585): Das Pass-Type-ID-Zertifikat gilt ein Jahr. 30 Tage vor Ablauf von Pass- oder WWDR-Zertifikat meldet der Site-Health-Test „Pass Captain Wallet“ eine Empfehlung und Administrator:innen sehen auf jeder Admin-Seite einen Hinweis. Ein **abgelaufenes** Zertifikat gilt als „nicht konfiguriert“: Es werden keine Apple-Pässe mehr erstellt oder per Push aktualisiert (statt Pässe mit ungültiger Signatur zu verschicken), Site Health meldet den Befund als kritisch; Google Wallet ist davon nicht betroffen. Nach dem Hinterlegen eines neuen Zertifikats arbeitet Apple Wallet sofort wieder.

Icons/Logo werden mit GD erzeugt (ohne GD: einfarbige PNGs); eigene PNGs können als Pfad hinterlegt werden (`…@2x.png` daneben wird automatisch verwendet) oder per Filter `wpcap_wallet_apple_images` ersetzt werden.

### Google Wallet

1. In der Google Pay & Wallet Console einen Aussteller (Issuer) anlegen, die **Issuer-ID** notieren.
2. In Google Cloud ein Servicekonto mit Zugriff auf die Wallet API anlegen, einen JSON-Schlüssel erzeugen und das Servicekonto in der Wallet Console als Nutzer hinzufügen.
3. `define( 'WPCAP_GOOGLE_WALLET_SERVICE_ACCOUNT', '/pfad/service-account.json' );` (Pfad oder JSON-Inhalt), Issuer-ID eintragen, „Google Wallet aktiv“ setzen.

Neue Klassen stehen bei Google zunächst auf `UNDER_REVIEW`; für den Livebetrieb muss der Aussteller freigeschaltet sein.

**Stabile IDs bei Domainwechsel (A475):** Klassen- und Objekt-IDs haben die Form `{issuer}.wpcap_{site}_o{termin}`
bzw. `{issuer}.wpcap_{site}_t{ticket}_g{generation}`. Der Site-Teil wird beim ersten Gebrauch festgelegt und in der
Option `wpcap_wallet_google_site_key` gespeichert (bis dahin: Hash der Startseiten-URL; bestehende Installationen
übernehmen den Schlüssel ihres zuletzt ausgestellten Passes). Ein späterer Umzug auf eine andere Domain ändert die
IDs nicht mehr – gespeicherte Pässe erhalten weiter Datum, Ort, Absage und Widerruf. Aktualisierungen verwenden
zudem die beim Ausstellen gespeicherten IDs (`wpcap_wallet_passes.serial_number`/`provider_ref`). Antwortet die
Wallet-API mit 404 (Klasse/Objekt unbekannt – noch nicht gespeichert oder unter anderer ID), steht das als
`google_not_found` im Protokoll der Wallet-Seite statt still als Erfolg zu gelten. Nach einem Domainwechsel muss
nur `origins` im Speichern-Link passen; das nimmt das Plugin immer aus der aktuellen Startseite.

**Speichern-Link (A583):** Die Mail enthält keinen Google-JWT mehr, sondern einen kurzen, signierten Link des Plugins (`/wp-json/pass-captain/v1/wallet/google/save?t={ticket}&sig=…`, HMAC über Ticket und aktuelle Pass-Generation – nach einer Code-Erneuerung ungültig). Erst beim Klick legt das Plugin Klasse und Objekt über die Wallet-API an (`POST`, bei 409 `PATCH` auf den aktuellen Stand) und leitet mit `302` auf `https://pay.google.com/gp/v/save/{jwt}` weiter; der JWT enthält dann nur noch die Objekt-IDs (gemessen rund 720 Zeichen für ein Ticket und rund 1.470 für 8 Tickets, statt über 1.800 für ein einzelnes vollständiges Objekt). Daten gehen so erst auf Klick der Kundin an Google. Ist die API nicht erreichbar, bekommt ein einzelnes Ticket als Rückfall den vollständigen JWT; für mehrere Tickets erscheint ein Hinweis (503), es später erneut zu versuchen.

**Nur gespeicherte Objekte werden aktualisiert (A566):** Mit dem Klick auf den Speichern-Link merkt sich das Plugin, dass das Objekt bei Google existiert (`wpcap_wallet_passes.remote_at`, Schema 3.10.18). Vorher sendet keine Aktualisierung (Terminänderung, Namenskorrektur, Widerruf, Code-Erneuerung) Ticketdaten an Google, und auch die Klasse eines Termins wird erst aktualisiert, wenn dort jemand ein Ticket gespeichert hat. Antwortet Google auf eine Aktualisierung mit 404 (Objekt gelöscht), legt das Plugin es **nicht** neu an. Wird ein gespeichertes Ticket ungültig (Widerruf, Erstattung, Absage, Datenlöschung), ersetzt das Plugin das Objekt (`PUT`) durch eines mit `state` `INACTIVE` **ohne** Barcode, Inhabername und Platz; ältere Generationen nach einer Code-Erneuerung ebenso. Leert der Aufbewahrungslauf die Teilnehmernamen (`wpcap_privacy_attendees_cleared`) oder wird ein Name entfernt, wird `ticketHolderName` im gespeicherten Objekt geleert. Bei der Datenschutz-Löschung einer Bestellung (`wpcap_privacy_order_anonymized`) werden alle ihre Pässe abgeglichen. Google-Pässe aus der Zeit vor Schema 3.10.18 gelten beim Upgrade als möglicherweise gespeichert und werden weiter aktualisiert (aber nie neu angelegt).

## Was im Pass steht

| | Apple (`pass.json`) | Google |
|---|---|---|
| Kennung | `serialNumber` = öffentliche Ticket-ID | Objekt `{issuer}.wpcap_{site}_t{ticket}_g{generation}`, Klasse `{issuer}.wpcap_{site}_o{occurrence}` |
| Barcode | QR (`PKBarcodeFormatQR`), Inhalt = Ticketcode | `QR_CODE`, Inhalt = Ticketcode |
| Felder | Veranstaltung, Datum/Uhrzeit in der Zeitzone des Termins (`ignoresTimeZone`), Einlass (falls gesetzt), Ort, Ticketkategorie, Platz, Teilnehmername; Ablauf 12 Stunden nach dem Ende | Eventname, Termin mit Ende und Einlass (`dateTime.start/end/doorsOpen`, Klasse), Ort, Ticketart, Platz, Inhaber |
| Updates | `webServiceURL` + `authenticationToken`, APNs-Push | REST-API (`PATCH`/`POST`) |

### Filter

- `wpcap_ticket_seat_label` (`string $label = '', int $ticket_id, array $ticket`) – Platz-/Bereichsbezeichnung für ein Ticket, z. B. „Reihe 3, Platz 7“. Wird vom Wallet-Modul definiert; ein Sitzplan-Modul kann ihn implementieren. Leer = kein Platzfeld.
- `wpcap_wallet_apple_locations` (`array $locations, array $ticket`) – optionale Geo-Relevanz (`latitude`, `longitude`, `relevantText`; max. 10).
- `wpcap_wallet_apple_pass_json`, `wpcap_wallet_google_class`, `wpcap_wallet_google_object` – letzte Anpassung der Payloads.
- `wpcap_wallet_apple_images` – Bilder des Passes (`name => PNG-Bytes`).
- `wpcap_wallet_apns_client` – eigene `PassCaptain\WalletPasses\ApnsClient`-Instanz (z. B. für Tests oder Proxy).

## Speicherung des Ticketcodes (Abwägung)

Der Kern speichert vom Ticketcode nur den SHA-256-Hash; im Klartext existiert er nur beim Versand der Ticketmail (`wpcap_ticket_mail`). Ein Wallet-Pass muss aber später neu gebaut werden können (Apple fragt nach einem Push den aktuellen Pass ab; gespeicherte Google-Objekte werden per API aktualisiert).

Das Modul speichert deshalb pro Ticket in `wpcap_wallet_state` den **Ticketcode und das Apple-Authentifizierungstoken verschlüsselt** (`sodium_crypto_secretbox`, XSalsa20-Poly1305). Der Schlüssel wird per HMAC aus `WPCAP_WALLET_ENCRYPTION_KEY` (falls in `wp-config.php` definiert, empfohlen) oder sonst aus `wp_salt( 'auth' )` abgeleitet. Für die Prüfung von Geräteanfragen wird nur der Hash des Tokens verwendet.

Konsequenzen:

- Wer Datenbank **und** Schlüssel (wp-config.php) besitzt, kann die Codes von Tickets mit Wallet-Pass entschlüsseln. Mit nur einem Datenbank-Dump ist das nicht möglich. Ohne Wallet bleibt der Kern bei „nur Hash“.
- Werden die WordPress-Salts bzw. `WPCAP_WALLET_ENCRYPTION_KEY` geändert, lassen sich gespeicherte Codes nicht mehr öffnen (A584). „Nicht entschlüsselbar“ heißt dabei **nicht** „ungültig“: Geräte erhalten beim Abruf `304 Not Modified` und behalten ihren letzten gültigen Pass, Google-Objekte werden ohne `state`/`barcode` gepatcht (bleiben also aktiv mit Code) und fehlende Google-Objekte werden nicht ohne Code angelegt; der Download-Link antwortet mit 503 und Hinweis. Widerrufene, erstattete oder abgesagte Tickets werden weiterhin ungültig. Das Modul merkt sich einen Fingerabdruck des Schlüssels (Option `wpcap_wallet_key_check`); ändert er sich, meldet der Site-Health-Test „Pass Captain Wallet“ einen kritischen Befund und Administrator:innen sehen einen Hinweis mit der Abhilfe (bisherigen Schlüssel als `WPCAP_WALLET_ENCRYPTION_KEY` wiederherstellen oder Tickets erneut senden). Solange der Schlüssel nur aus den Salts abgeleitet wird, empfiehlt Site Health (und die Wallet-Seite) eine eigene, stabile Konstante `WPCAP_WALLET_ENCRYPTION_KEY`. Ein bereits versendeter Apple-Download-Link ist mit dem Schlüssel signiert und wird nach einer Schlüsseländerung ungültig.
- Der fertige Pass wird nicht gespeichert, sondern bei Bedarf aus aktuellen Daten neu gebaut und signiert.
- Der Google-Speichern-Link und der Apple-Download-Link liefern den Code – sie stehen nur in der Mail, die den Code ohnehin enthält. Der Download-Link ist mit einem HMAC über Seriennummer und aktuelle Token-Generation signiert und wird bei einer Code-Erneuerung ungültig.

## Aktualisierungen

| Auslöser | Hook | Wirkung |
|---|---|---|
| Ticket widerrufen | `wpcap_ticket_revoked` | Apple: Pass `voided`, ohne Barcode; Google (nur gespeicherte Objekte): Objekt ersetzt, `INACTIVE`, ohne Barcode, Name und Platz |
| Termin verschoben | `wpcap_occurrence_rescheduled` | neues Datum im Pass; Google-Klasse `dateTime` aktualisiert |
| Ort, Ende, Einlass, Status eines Termins geändert (A559) | `wpcap_occurrence_changed` | Pass und Google-Klasse (Ort, `dateTime`) neu; Apple zeigt „Neuer Ort“/„Neuer Einlass“ als Änderungshinweis |
| Eventname geändert (A582) | `wpcap_event_updated` | alle Pässe des Events und die Google-Klassen seiner Termine |
| Ticketartname geändert (A582) | `wpcap_ticket_type_updated` | Pässe dieser Ticketart (Apple „Ticket“, Google `ticketType`) |
| Teilnehmername korrigiert, gleiche Person (A582) | `wpcap_record_corrected` (Ticket-Admin), `wpcap_ticket_attendee_updated` (Bestellportal) | neuer Name im Pass, Code und Pass-Generation bleiben |
| Termin abgesagt | `wpcap_occurrence_cancelled` | Pass `voided` mit Hinweis „abgesagt“ |
| Bestellung storniert / erstattet | `wpcap_order_cancelled`, `wpcap_refund_completed` | Abgleich aller Tickets der Bestellung |
| Ticketcode erneuert | siehe unten | neue Pass-Generation |
| Ticket umgetauscht (A490) | `wpcap_ticket_revoked` (altes Ticket), `wpcap_ticket_code_issued` (neues Ticket) | alter Pass ungültig; neues Ticket bekommt Pässe, die Umtauschmail die Wallet-Links |

Jede Änderung wird über einen Fingerabdruck der passrelevanten Daten erkannt (keine Pushes ohne Änderung). Hooks wie `wpcap_ticket_revoked` feuern pro Ticket (z. B. hundertfach bei einer Terminabsage); das Modul sammelt die betroffenen Tickets und Termine deshalb nur ein und verarbeitet sie **einmal am Ende des Requests** (`shutdown`). Dabei wird immer zuerst ein Job `wallet_sync` in `PassCaptain\Jobs` angelegt (Wiederholung mit Backoff), damit ein Timeout keine Aktualisierung verliert; bis zu 20 Tickets werden danach sofort verarbeitet (APNs-Push an alle registrierten Geräte, Google-`PATCH` bzw. `PUT` nur für Objekte, die die Kundin gespeichert hat; fehlt ein Objekt, wird es nicht angelegt – der Speichern-Link legt beim Klick ohnehin den aktuellen Stand an, A566) und der Job bei Erfolg abgeschlossen. Bei verschobenen oder abgesagten Terminen wird die Google-Klasse (Datum, Ort, Hinweis „Abgesagt“) immer aktualisiert, auch wenn die Tickets schon vorher über `wpcap_ticket_revoked` abgeglichen wurden. APNs-Antwort 410 entfernt die Geräte-Registrierung.

### Ticketcode-Erneuerung (erneuter Versand, Übertragung, Teilnehmerzuweisung, Neudruck)

Der Kern feuert `wpcap_ticket_code_rotated( $ticket_id, $code, $reason )` (`resend`, `transfer`, `assign`, `print`) und ergänzt die zugehörigen Mails über den Filter `wpcap_ticket_code_mail_section( $text, $ticket_id )`. Das Modul legt dann eine neue **Pass-Generation** an und hängt „Zu Apple Wallet hinzufügen“ (Download-Link) / „In Google Wallet speichern“ an die Mail an. Der Grund spielt keine Rolle; mehrfacher Aufruf mit demselben Code ist wirkungslos.

Eine neue Generation bedeutet: neues Apple-Authentifizierungstoken (die Seriennummer bleibt), neues Google-Objekt. Geräte mit dem alten Token erhalten per Push einen ungültigen (`voided`) Pass ohne Code und ohne personenbezogene Felder des neuen Inhabers (Name, Platz); das alte Google-Objekt wird `INACTIVE`. So bekommt bei einer Übertragung der bisherige Inhaber nie den neuen Code oder die Daten des neuen Inhabers – auch bei einem erneuten Versand an dieselbe Person muss der Pass aus der neuen Mail neu hinzugefügt werden (bewusst konservativ).

Wird ein Code erneuert, ohne dass das Modul ihn sieht, passt der gespeicherte Code nicht mehr zum Ticket-Hash; der Pass wird dann als ungültig ausgeliefert, nie mit einem falschen Code.

## PassKit-Webservice

Basis: `https://example.com/wp-json/pass-captain/v1/wallet/apple` (= `webServiceURL`). Geräte authentifizieren sich mit `Authorization: ApplePass <authenticationToken>`; Tokens sind zufällig (40 Hex-Zeichen) pro Pass-Generation und werden als SHA-256 gespeichert.

| Methode | Pfad | Antwort |
|---|---|---|
| POST | `/v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}/{serialNumber}` (Body `{"pushToken": "…"}`) | 201 neu, 200 vorhanden, 401, 429 |
| DELETE | gleicher Pfad | 200, 401, 429 |
| GET | `/v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}?passesUpdatedSince=tag` | 200 `{serialNumbers, lastUpdated}`, 204, 404 (unbekanntes Gerät), 429 |
| GET | `/v1/passes/{passTypeIdentifier}/{serialNumber}` | 200 `.pkpass` mit `Last-Modified` und `ETag`, 304 bei passendem `If-None-Match` bzw. `If-Modified-Since`, 401, 429 |
| POST | `/v1/log` | 200; ohne Authentifizierung (Apple-Vorgabe), daher eigener kleiner Puffer (10 Einträge, max. 3 Zeilen je Aufruf, 5 Aufrufe/Stunde je Client, 30/Stunde gesamt), getrennt vom Server-Protokoll, Tokens entfernt |
| GET | `/download/{serialNumber}?sig=…` | Download-Link aus der Mail |
| GET | `/bundle?o={bestellung}&p={paket}&sig=…` | A580: „Alle zu Apple Wallet hinzufügen“ – bis zu 10 Pässe der Bestellung als `application/vnd.apple.pkpasses` (iOS 15+) |
| GET | `/wp-json/pass-captain/v1/wallet/google/save?t=…&sig=…` bzw. `?o=…&p=…&sig=…` | A583: kurzer Google-Speichern-Link (ein Ticket bzw. ein Paket von bis zu 8 Tickets der Bestellung), 302 zu Google |

**„Alle hinzufügen“ (A580):** Ab mehr als 10 Tickets (Filter `wpcap_wallet_mail_inline_limit`) hängt die Ticketmail keine `.pkpass` je Ticket mehr an und listet keine Links je Ticket, sondern je Paket einen Apple- und einen Google-Link („Tickets 1–10 zu Apple Wallet hinzufügen“). Codes und Pass-Zeilen werden trotzdem für jedes Ticket gespeichert; die Pässe werden beim Abruf gebaut. Ein Paket umfasst die gültigen Tickets, die noch bei der Käuferin liegen (nicht übertragen, Code nicht an eine andere Adresse gegangen – wie beim PDF im Bestellportal; eine Teilnehmer-E-Mail aus dem Checkout allein zählt nicht, A581). Die Signatur hängt am Zugangstoken der Bestellung: ein neuer Bestelllink macht auch diese Links ungültig.

**Ratenlimits (A461):** Ein Limit wird immer mit HTTP 429 und `Retry-After` (Sekunden bis zum Ende des Zeitfensters) beantwortet, nie mit 401 oder 404 – ein 401 hält das Gerät für einen ungültigen Pass, ein 404 die Käuferin für einen falschen Link. Gezählt wird je Seriennummer (authentifizierte Geräteanfragen, Standard 120/Minute), je Geräte-ID (Seriennummernliste, 120/Minute) und je Client-Adresse nur für Fehlversuche (falscher Token, unbekannte Seriennummer oder Geräte-ID; 60/Minute) – viele Telefone hinter einer Veranstaltungs- oder Mobilfunk-NAT teilen sich so kein Budget. Download- und Paket-Links zählen je Client (60/Minute), Google-Speichern-Links ebenso (30/Minute). Alle Werte über den Filter `wpcap_wallet_rate_limits`.

APNs: HTTP/2-POST an `https://api.push.apple.com/3/device/{pushToken}` (Sandbox einstellbar) mit dem Pass-Type-ID-Zertifikat als Client-Zertifikat, `apns-topic` = Pass Type ID, Payload `{}`. Benötigt cURL mit HTTP/2.

Update-Tags (für `passesUpdatedSince`, `Last-Modified`, `ETag`) liegen bei jeder Änderung in einer späteren vollen Sekunde als der vorherige Tag; eine Änderung in derselben Sekunde wie der letzte Abruf wird daher nie per 304 verschluckt.

## Nicht enthalten / offen

- Bestellportal: Das Portal (`OrderPortal`, Template) bietet keinen Hook für zusätzliche Links je Ticket; Wallet-Buttons dort benötigen eine Kernerweiterung (z. B. Filter je Ticketzeile). Der Code ist dort ohnehin nicht im Klartext verfügbar – das Portal könnte aber `Service::download_url()` für vorhandene Apple-Pässe anzeigen.
- Tickets, die vor Aktivierung des Moduls ausgestellt wurden, erhalten erst nach einer Code-Erneuerung (z. B. „Tickets erneut senden“) einen Pass.
- Check-in ändert den Pass nicht.
- Google-Speichern-Links: siehe oben (A583) – der vollständige JWT („fat JWT“) wird nur noch als Rückfall für ein einzelnes Ticket erzeugt, wenn die Wallet-API beim Klick nicht erreichbar ist.
