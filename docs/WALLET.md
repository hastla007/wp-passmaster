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

   Alternativ Umgebungsvariablen gleichen Namens oder die Felder auf der Wallet-Seite (dann in der Option `wpcap_settings` gespeichert).
3. Auf der Wallet-Seite Pass Type ID, Team-ID (10 Zeichen) eintragen und „Apple Wallet aktiv“ setzen. Die Statusübersicht zeigt Gültigkeit von Pass- und WWDR-Zertifikat, ob Schlüssel und Zertifikat zusammenpassen und ob die Pass Type ID zum Zertifikat gehört. „Test-Pass herunterladen“ erzeugt einen signierten Beispielpass.
4. Versand: als `.pkpass`-Anhang (Standard) oder als signierter Download-Link.

Icons/Logo werden mit GD erzeugt (ohne GD: einfarbige PNGs); eigene PNGs können als Pfad hinterlegt werden (`…@2x.png` daneben wird automatisch verwendet) oder per Filter `wpcap_wallet_apple_images` ersetzt werden.

### Google Wallet

1. In der Google Pay & Wallet Console einen Aussteller (Issuer) anlegen, die **Issuer-ID** notieren.
2. In Google Cloud ein Servicekonto mit Zugriff auf die Wallet API anlegen, einen JSON-Schlüssel erzeugen und das Servicekonto in der Wallet Console als Nutzer hinzufügen.
3. `define( 'WPCAP_GOOGLE_WALLET_SERVICE_ACCOUNT', '/pfad/service-account.json' );` (Pfad oder JSON-Inhalt), Issuer-ID eintragen, „Google Wallet aktiv“ setzen.

Neue Klassen stehen bei Google zunächst auf `UNDER_REVIEW`; für den Livebetrieb muss der Aussteller freigeschaltet sein.

## Was im Pass steht

| | Apple (`pass.json`) | Google |
|---|---|---|
| Kennung | `serialNumber` = öffentliche Ticket-ID | Objekt `{issuer}.wpcap_{site}_t{ticket}_g{generation}`, Klasse `{issuer}.wpcap_{site}_o{occurrence}` |
| Barcode | QR (`PKBarcodeFormatQR`), Inhalt = Ticketcode | `QR_CODE`, Inhalt = Ticketcode |
| Felder | Veranstaltung, Datum/Uhrzeit in der Zeitzone des Termins (`ignoresTimeZone`), Ort, Ticketkategorie, Platz, Teilnehmername | Eventname, Termin (Klasse), Ort, Ticketart, Platz, Inhaber |
| Updates | `webServiceURL` + `authenticationToken`, APNs-Push | REST-API (`PATCH`/`POST`) |

### Filter

- `wpcap_ticket_seat_label` (`string $label = '', int $ticket_id, array $ticket`) – Platz-/Bereichsbezeichnung für ein Ticket, z. B. „Reihe 3, Platz 7“. Wird vom Wallet-Modul definiert; ein Sitzplan-Modul kann ihn implementieren. Leer = kein Platzfeld.
- `wpcap_wallet_apple_locations` (`array $locations, array $ticket`) – optionale Geo-Relevanz (`latitude`, `longitude`, `relevantText`; max. 10).
- `wpcap_wallet_apple_pass_json`, `wpcap_wallet_google_class`, `wpcap_wallet_google_object` – letzte Anpassung der Payloads.
- `wpcap_wallet_apple_images` – Bilder des Passes (`name => PNG-Bytes`).
- `wpcap_wallet_apns_client` – eigene `PassCaptain\WalletPasses\ApnsClient`-Instanz (z. B. für Tests oder Proxy).

## Speicherung des Ticketcodes (Abwägung)

Der Kern speichert vom Ticketcode nur den SHA-256-Hash; im Klartext existiert er nur beim Versand der Ticketmail (`wpcap_ticket_mail`). Ein Wallet-Pass muss aber später neu gebaut werden können (Apple fragt nach einem Push den aktuellen Pass ab; Google-Objekte werden per API aktualisiert oder angelegt).

Das Modul speichert deshalb pro Ticket in `wpcap_wallet_state` den **Ticketcode und das Apple-Authentifizierungstoken verschlüsselt** (`sodium_crypto_secretbox`, XSalsa20-Poly1305). Der Schlüssel wird per HMAC aus `WPCAP_WALLET_ENCRYPTION_KEY` (falls in `wp-config.php` definiert, empfohlen) oder sonst aus `wp_salt( 'auth' )` abgeleitet. Für die Prüfung von Geräteanfragen wird nur der Hash des Tokens verwendet.

Konsequenzen:

- Wer Datenbank **und** Schlüssel (wp-config.php) besitzt, kann die Codes von Tickets mit Wallet-Pass entschlüsseln. Mit nur einem Datenbank-Dump ist das nicht möglich. Ohne Wallet bleibt der Kern bei „nur Hash“.
- Werden die WordPress-Salts bzw. `WPCAP_WALLET_ENCRYPTION_KEY` geändert, lassen sich gespeicherte Codes nicht mehr öffnen: Pässe werden dann ohne Barcode (mit Hinweis) ausgeliefert, die Tickets selbst bleiben gültig. Deshalb einen eigenen, stabilen `WPCAP_WALLET_ENCRYPTION_KEY` setzen.
- Der fertige Pass wird nicht gespeichert, sondern bei Bedarf aus aktuellen Daten neu gebaut und signiert.
- Der Google-Speichern-Link (JWT) und der Apple-Download-Link enthalten bzw. liefern den Code – sie stehen nur in der Mail, die den Code ohnehin enthält. Der Download-Link ist mit einem HMAC über Seriennummer und aktuelle Token-Generation signiert und wird bei einer Code-Erneuerung ungültig.

## Aktualisierungen

| Auslöser | Hook | Wirkung |
|---|---|---|
| Ticket widerrufen | `wpcap_ticket_revoked` | Apple: Pass `voided`, ohne Barcode; Google: Objekt `INACTIVE` |
| Termin verschoben | `wpcap_occurrence_rescheduled` | neues Datum im Pass; Google-Klasse `dateTime` aktualisiert |
| Termin abgesagt | `wpcap_occurrence_cancelled` | Pass `voided` mit Hinweis „abgesagt“ |
| Bestellung storniert / erstattet | `wpcap_order_cancelled`, `wpcap_refund_completed` | Abgleich aller Tickets der Bestellung |
| Ticketcode erneuert | siehe unten | neue Pass-Generation |

Jede Änderung wird über einen Fingerabdruck der passrelevanten Daten erkannt (keine Pushes ohne Änderung). Hooks wie `wpcap_ticket_revoked` feuern pro Ticket (z. B. hundertfach bei einer Terminabsage); das Modul sammelt die betroffenen Tickets und Termine deshalb nur ein und verarbeitet sie **einmal am Ende des Requests** (`shutdown`). Dabei wird immer zuerst ein Job `wallet_sync` in `PassCaptain\Jobs` angelegt (Wiederholung mit Backoff), damit ein Timeout keine Aktualisierung verliert; bis zu 20 Tickets werden danach sofort verarbeitet (APNs-Push an alle registrierten Geräte, Google-`PATCH`; fehlt das Google-Objekt noch, werden Klasse und Objekt per `POST` angelegt, damit ein später benutzter Speichern-Link nicht den alten Stand anlegt) und der Job bei Erfolg abgeschlossen. Bei verschobenen oder abgesagten Terminen wird die Google-Klasse (Datum, Ort, Hinweis „Abgesagt“) immer aktualisiert, auch wenn die Tickets schon vorher über `wpcap_ticket_revoked` abgeglichen wurden. APNs-Antwort 410 entfernt die Geräte-Registrierung.

### Ticketcode-Erneuerung (erneuter Versand, Übertragung, Teilnehmerzuweisung, Neudruck)

Der Kern feuert `wpcap_ticket_code_rotated( $ticket_id, $code, $reason )` (`resend`, `transfer`, `assign`, `print`) und ergänzt die zugehörigen Mails über den Filter `wpcap_ticket_code_mail_section( $text, $ticket_id )`. Das Modul legt dann eine neue **Pass-Generation** an und hängt „Zu Apple Wallet hinzufügen“ (Download-Link) / „In Google Wallet speichern“ an die Mail an. Der Grund spielt keine Rolle; mehrfacher Aufruf mit demselben Code ist wirkungslos.

Eine neue Generation bedeutet: neues Apple-Authentifizierungstoken (die Seriennummer bleibt), neues Google-Objekt. Geräte mit dem alten Token erhalten per Push einen ungültigen (`voided`) Pass ohne Code und ohne personenbezogene Felder des neuen Inhabers (Name, Platz); das alte Google-Objekt wird `INACTIVE`. So bekommt bei einer Übertragung der bisherige Inhaber nie den neuen Code oder die Daten des neuen Inhabers – auch bei einem erneuten Versand an dieselbe Person muss der Pass aus der neuen Mail neu hinzugefügt werden (bewusst konservativ).

Wird ein Code erneuert, ohne dass das Modul ihn sieht, passt der gespeicherte Code nicht mehr zum Ticket-Hash; der Pass wird dann als ungültig ausgeliefert, nie mit einem falschen Code.

## PassKit-Webservice

Basis: `https://example.com/wp-json/wp-pass-captain/v1/wallet/apple` (= `webServiceURL`). Geräte authentifizieren sich mit `Authorization: ApplePass <authenticationToken>`; Tokens sind zufällig (40 Hex-Zeichen) pro Pass-Generation und werden als SHA-256 gespeichert.

| Methode | Pfad | Antwort |
|---|---|---|
| POST | `/v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}/{serialNumber}` (Body `{"pushToken": "…"}`) | 201 neu, 200 vorhanden, 401 |
| DELETE | gleicher Pfad | 200, 401 |
| GET | `/v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}?passesUpdatedSince=tag` | 200 `{serialNumbers, lastUpdated}`, 204, 404 (unbekanntes Gerät) |
| GET | `/v1/passes/{passTypeIdentifier}/{serialNumber}` | 200 `.pkpass` mit `Last-Modified` und `ETag`, 304 bei passendem `If-None-Match` bzw. `If-Modified-Since`, 401 |
| POST | `/v1/log` | 200; ohne Authentifizierung (Apple-Vorgabe), daher eigener kleiner Puffer (10 Einträge, max. 3 Zeilen je Aufruf, 5 Aufrufe/Stunde je Client, 30/Stunde gesamt), getrennt vom Server-Protokoll, Tokens entfernt |
| GET | `/download/{serialNumber}?sig=…` | Download-Link aus der Mail |

APNs: HTTP/2-POST an `https://api.push.apple.com/3/device/{pushToken}` (Sandbox einstellbar) mit dem Pass-Type-ID-Zertifikat als Client-Zertifikat, `apns-topic` = Pass Type ID, Payload `{}`. Benötigt cURL mit HTTP/2.

Update-Tags (für `passesUpdatedSince`, `Last-Modified`, `ETag`) liegen bei jeder Änderung in einer späteren vollen Sekunde als der vorherige Tag; eine Änderung in derselben Sekunde wie der letzte Abruf wird daher nie per 304 verschluckt.

## Nicht enthalten / offen

- Bestellportal: Das Portal (`OrderPortal`, Template) bietet keinen Hook für zusätzliche Links je Ticket; Wallet-Buttons dort benötigen eine Kernerweiterung (z. B. Filter je Ticketzeile). Der Code ist dort ohnehin nicht im Klartext verfügbar – das Portal könnte aber `Service::download_url()` für vorhandene Apple-Pässe anzeigen.
- Tickets, die vor Aktivierung des Moduls ausgestellt wurden, erhalten erst nach einer Code-Erneuerung (z. B. „Tickets erneut senden“) einen Pass.
- Check-in ändert den Pass nicht.
- Google-Speichern-Links enthalten Klasse und Objekt vollständig („fat JWT“); sehr lange Texte können die URL-Länge überschreiten.
