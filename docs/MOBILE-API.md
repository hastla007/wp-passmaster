# Mobile Scanner-API (v1)

REST-Schnittstelle für native Einlass-Apps (iOS/Android) und andere Scanner-Clients. Basis-URL:

```
https://<site>/wp-json/wp-pass-captain/v1/app/
```

Die Web-Scanner-Seite (Shortcode `[wp_pass_captain_scanner]`) und die App nutzen denselben Check-in-Dienst (`PassCaptain\Checkin`). Ein Ticket kann deshalb nie über zwei Kanäle doppelt eingelassen werden.

## 1. Geräte koppeln

1. Eine Person mit Einlassrecht (`wpcap_checkin`) öffnet **Pass Captain → Scanner-Geräte**. Dort wählt sie die Events, einen Gerätenamen und die Gültigkeit in Stunden (höchstens `device_max_hours`, Standard 24) und klickt **Kopplungs-QR erstellen**.
2. Die Seite zeigt einen QR-Code und den Code zum Abtippen. Beide sind 10 Minuten und nur einmal gültig. Der QR enthält:
   ```json
   {"type":"wpcap-pair","v":1,"api":"https://example.org/wp-json/wp-pass-captain/v1/app/","code":"K7M2Q9X4..."}
   ```
3. Die App scannt den QR (oder der Code wird eingetippt) und ruft auf:

```http
POST /app/pair
Content-Type: application/json

{"code":"K7M2-Q9X4-…","device_label":"iPhone Einlass Nord","platform":"ios","app_version":"1.0.0"}
```

```json
201 Created
{
  "token": "wpcapd_3f…(32 hex)…_9a…(64 hex)…",
  "token_type": "Bearer",
  "device_id": "3f…",
  "expires_at": "2026-11-12T18:00:00+00:00",
  "user": {"display_name": "Anna Einlass"},
  "event_ids": [12]
}
```

Das Token wird nur einmal ausgeliefert. Die App speichert es im sicheren Speicher (Keychain bzw. Android Keystore) und sendet es bei jedem Aufruf mit:

```
Authorization: Bearer wpcapd_<device>_<secret>
```

**Eigenschaften des Tokens**

- Personengebunden: Das Gerät handelt im Namen der Person, die gekoppelt hat. Es gibt keine geteilten Konten.
- Das Token gilt nur für die gewählten Events und zusätzlich nur, solange die Person Einlassrechte für diese Events hat.
- Es läuft automatisch ab und kann von der Person selbst oder von Admins widerrufen werden, über **Scanner-Geräte** oder `POST /app/logout`.
- Serverseitig wird nur ein SHA-256-Hash des Secrets gespeichert.
- Rate-Limits: 600 Anfragen pro Minute und Gerät, 10 Kopplungsversuche pro Stunde und IP-Adresse.

## 2. Endpunkte

| Methode | Pfad | Zweck |
|---|---|---|
| POST | `/app/pair` | Kopplung (ohne Token) |
| GET | `/app/me` | Person, Gerät, Ablauf, freigegebene Events, Feature-Flags, Serverzeit |
| GET | `/app/occurrences` | Termine der freigegebenen Events ab gestern (max. 100) |
| GET | `/app/occurrences/{id}/stats` | Tickets gesamt, eingecheckt, offen, von diesem Gerät eingecheckt |
| POST | `/app/scan/validate` | Vorschau ohne Einlass |
| POST | `/app/scan/check-in` | Einlass (benötigt `idempotency_key`) |
| POST | `/app/scan/undo` | Einlass zurücknehmen (mit `reason`) |
| POST | `/app/scan/sync` | Offline-Scans hochladen (1–200) |
| POST | `/app/logout` | Gerät widerrufen |

### Scan-Anfragen

`code` ist der gescannte QR-Inhalt. Akzeptiert werden der reine Code (64 Hex-Zeichen) und der Ticket-Link, der ihn enthält. Mit `occurrence_id` prüft der Server zusätzlich, ob das Ticket zum gewählten Termin gehört.

```http
POST /app/scan/check-in
Authorization: Bearer wpcapd_…
Content-Type: application/json

{"code":"<64 hex>","occurrence_id":34,"idempotency_key":"7b0e2c8a-…"}
```

```json
200 OK
{"ok":true,"result":"checked_in","status":"checked_in","ticket_public_id":"…","event_id":12,"occurrence_id":34,
 "event":"Konzert","ticket":"Standard","starts_at":"2026-12-01T18:00:00+00:00","timezone":"Europe/Vienna"}
```

**Idempotenz:** Die App erzeugt pro Scan einen neuen Schlüssel (UUID) und verwendet ihn bei Wiederholungen nach Netzwerkfehlern erneut. Eine Wiederholung liefert dann das ursprüngliche Ergebnis mit `"replayed": true` und zählt nicht doppelt.

### Ergebnis-Codes (`result`)

Ein Scan-Ergebnis ist kein Transportfehler. Es kommt als HTTP 200 mit `ok: false`.

| `result` | Bedeutung | Anzeige-Empfehlung |
|---|---|---|
| `checked_in` | Einlass erfolgt | grün |
| `valid` | (nur `validate`) Ticket wäre gültig | grün/neutral |
| `already_checked_in` | bereits verwendet | rot, ggf. Uhrzeit nachfragen |
| `revoked` | widerrufen/erstattet | rot |
| `unknown` | Code unbekannt | rot |
| `wrong_event` | Ticket für ein anderes Event als dieses Gerät | gelb |
| `wrong_occurrence` | Ticket für einen anderen Termin | gelb |
| `cancelled_event` | Termin/Event abgesagt | rot |
| `out_of_window` | außerhalb des Einlasszeitraums | gelb |
| `not_valid_today` / `daily_limit_reached` | Mehrtagesregeln | gelb |
| `forbidden` | Person hat keinen Einlasszugriff (mehr) | rot, neu koppeln |

HTTP-Fehler gibt es nur bei Authentifizierung (401), Berechtigung oder Rate-Limit (403/429) und ungültigen Anfragen (400).

`attendee_name` wird nur mitgeliefert, wenn die Einstellung „Teilnehmername im Scanner anzeigen“ (`scanner_show_attendee`) aktiv ist. E-Mail- und Zahlungsdaten liefert die API nie aus.

## 3. Offline-Betrieb

- Bei fehlender Verbindung speichert die App Scans lokal mit Code, `idempotency_key` und `scanned_at` (ISO 8601). Lokale Daten werden verschlüsselt gespeichert und nach erfolgreicher Synchronisierung gelöscht.
- Wieder online, ruft sie `POST /app/scan/sync` mit `{"scans":[…]}` auf. Mehrfaches Hochladen ist durch den Schlüssel unschädlich.
- `conflict: true` heißt: Das Ticket wurde anderswo bereits eingelassen, das Offline-Gerät hat es trotzdem durchgelassen. Solche Fälle erscheinen im Check-in-Protokoll (`source = offline`) und müssen manuell geklärt werden.
- **Offline-Betrieb kann keine globale Einmaligkeit garantieren** (siehe CONCEPT.md, Abschnitt 7). Für streng begrenzte Einlässe nur online scannen.

## 4. Empfehlungen für App-Umsetzung

- **Scannen:** native Kamera-APIs (iOS AVFoundation/VisionKit, Android ML Kit/CameraX). Gleichen Code innerhalb von ca. 5 s ignorieren.
- **Feedback:** grün, rot oder gelb plus Ton bzw. Vibration. Die Texte kommen aus `message` und sind übersetzt (Deutsch/Englisch).
- **Uhrzeit:** `server_time` aus `/app/me` gegen die Gerätezeit prüfen und bei mehr als 2 Minuten Abweichung warnen, weil `scanned_at` für Offline-Scans zählt.
- **Ablauf und Widerruf:** Bei 401 das Token verwerfen und die Kopplung neu starten.
- **Kein Speichern von Personendaten** über die Sitzung hinaus.

## 5. Sicherheit

- Nur HTTPS verwenden, zusätzlich Certificate Pinning empfehlen.
- Tokens sind kurzlebig (Standard 24 h) und eventgebunden. Wird ein Gerät verloren, unter **Scanner-Geräte** sofort widerrufen.
- Jeder Einlass wird mit Person, Gerät, Quelle (`web`/`app`/`offline`), Server- und Gerätezeit protokolliert (`wpcap_checkins`, Audit-Log).
- Verliert eine Person ihre Rolle oder Event-Zuweisung, verliert auch ihr Gerät sofort den Zugriff.

## 6. Versionierung und Ausbau

- Die Version steht in `/app/me` als `api_version` (aktuell `1.0`). Änderungen, die bestehende Apps brechen, bekommen einen neuen Pfad (`/app/v2/…`). Neue Felder sind jederzeit möglich, Apps müssen unbekannte Felder ignorieren.
- **Geplant (nicht umgesetzt):**
  - Offline-Prüfpaket: signierte Liste gültiger Ticket-Hashes pro Termin für echte Offline-Validierung.
  - Push-Benachrichtigung bei Gerätewiderruf.
  - Suche nach Teilnehmenden mit eigenem Recht.
  - Badge-Druck.
  - Gerätebindung per App Attest / Play Integrity.
  - Mehrere Einlassbereiche pro Event.
