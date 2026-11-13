# Mobile Scanner-API (v1)

> Offline-Betrieb mit signierten Prüfpaketen und installierbarer Scanner-Web-App: siehe [OFFLINE-SCANNER.md](OFFLINE-SCANNER.md).

REST-Schnittstelle für native Einlass-Apps (iOS/Android) und andere Scanner-Clients. Basis-URL:

```
https://<site>/wp-json/pass-captain/v1/app/
```

Die Web-Scanner-Seite (Shortcode `[wp_pass_captain_scanner]`) und die App nutzen denselben Check-in-Dienst (`PassCaptain\Checkin`). Ein Ticket kann deshalb nie über zwei Kanäle doppelt eingelassen werden.

## 1. Geräte koppeln

1. Eine Person mit Einlassrecht (`wpcap_checkin`) öffnet **Pass Captain → Scanner-Geräte**. Dort wählt sie die Events, optional einen festen **Einlassbereich** (siehe Abschnitt 3), einen Gerätenamen und die Gültigkeit in Stunden (höchstens `device_max_hours`, Standard 24) und klickt **Kopplungs-QR erstellen**.
2. Die Seite zeigt einen QR-Code und den Code zum Abtippen. Beide sind 10 Minuten und nur einmal gültig. Der QR enthält:
   ```json
   {"type":"wpcap-pair","v":1,"api":"https://example.org/wp-json/pass-captain/v1/app/","code":"K7M2Q9X4..."}
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
  "event_ids": [12],
  "area": {"id": 3, "name": "VIP", "event_id": 12}
}
```

`area` ist `null`, wenn das Gerät keinem Bereich fest zugeordnet ist.

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
| GET | `/app/me` | Person, Gerät, Ablauf, freigegebene Events, Gerätebereich (`area`), Feature-Flags (`check_out`, `areas`), Serverzeit |
| GET | `/app/occurrences` | Termine der freigegebenen Events ab gestern (max. 100), je Termin mit `areas` (aktive Einlassbereiche des Events) und `doors_open_at` (Einlassbeginn, ISO 8601, oder `null`) |
| GET | `/app/occurrences/{id}/stats` | Tickets gesamt, eingecheckt, offen, von diesem Gerät eingecheckt, aktuell anwesend, Einlässe/Auslässe, Werte pro Bereich |
| POST | `/app/scan/validate` | Vorschau ohne Einlass |
| POST | `/app/scan/check-in` | Einlass (benötigt `idempotency_key`) |
| POST | `/app/scan/check-out` | Auslass/Check-out (benötigt `idempotency_key`) |
| POST | `/app/scan/undo` | Einlass zurücknehmen (mit `reason`) |
| POST | `/app/scan/sync` | Offline-Scans hochladen (1–200) |
| POST | `/app/logout` | Gerät widerrufen |

### Scan-Anfragen

`code` ist der gescannte QR-Inhalt. Akzeptiert werden der reine Code (64 Hex-Zeichen) und der Ticket-Link, der ihn enthält. Für die manuelle Eingabe, wenn der QR-Code nicht lesbar ist, nimmt `code` auch den 8-stelligen **Hilfscode** des Tickets an (z. B. `K7QM-4XWA`; Groß-/Kleinschreibung, Leerzeichen und Bindestrich egal). Hilfscode-Eingaben sind gedrosselt; darüber antwortet die API mit HTTP 429 (`code: wpcap_help_code_rate_limited`). Ergebnisse per Hilfscode tragen `help_code: true` (siehe [SECURITY.md](SECURITY.md#hilfscode-für-den-einlass)). Mit `occurrence_id` prüft der Server zusätzlich, ob das Ticket zum gewählten Termin gehört.

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

Ist das Gerät keinem Bereich fest zugeordnet, kann die App den Bereich pro Scan mit `area_id` mitsenden (Liste aus `/app/occurrences`). Ein fest zugeordneter Gerätebereich hat immer Vorrang.

`validate` liefert für gültige Tickets im Feld `status`/`result` das Ergebnis, das ein Einlass jetzt hätte (z. B. `not_valid_today`), und `ok: true` nur bei `valid`.

**Check-out:** `POST /app/scan/check-out` mit denselben Feldern wie der Check-in. Erfolgreich: `{"ok":true,"result":"checked_out",…}`. Ist das Ticket nicht eingecheckt: `ok:false`, `result:"not_checked_in"`. Check-outs werden getrennt protokolliert (`result = checked_out`) und für die Anwesenheitszählung verwendet. Sie verbrauchen keinen Einlass; ob danach ein erneuter Einlass möglich ist, bestimmt die Einlassregel des Tickettyps.

**Statistik** (`/app/occurrences/{id}/stats`):

```json
{"occurrence_id":34,"tickets_total":120,"checked_in":80,"remaining":40,"checked_in_by_this_device":12,
 "present":71,"entries":95,"checked_out_total":24,"device_area_id":3,
 "by_area":[{"area_id":3,"name":"VIP","present":10,"entries":14,"checked_out":4}]}
```

`present` = Tickets, die aktuell drinnen sind (Einlass ohne folgenden Auslass). Bei Mehrfach- und Tagestickets bleibt der Ticketstatus `valid`; maßgeblich sind dann `entries`/`present`.

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
| `out_of_window` | Termin liegt weit außerhalb (Plausibilitätsprüfung) | gelb |
| `outside_entry_window` | außerhalb des Einlassfensters der Einlassregel (z. B. vor „1 h vor Beginn“, nach Ende, außerhalb der täglichen Einlasszeit) oder vor der Einlasszeit des Termins (`doors_open_at`) | gelb |
| `not_valid_today` | Ticket gilt heute nicht (Tages-/Mehrtagesticket, Tag in der Zeitzone des Termins) | gelb |
| `daily_limit_reached` | heute bereits eingelassen (Tages-/Mehrtagesticket) | rot |
| `entry_limit_reached` | maximale Anzahl Einlässe erreicht (Mehrfach-/Wiedereinlass) | rot |
| `already_inside` | Wiedereinlass-Ticket ist noch drinnen; zuerst Check-out | gelb |
| `area_full` | Kapazität des Einlassbereichs erreicht (Personen gleichzeitig drinnen); Einlass erst nach Auslässen. Gilt nicht für Offline-Uploads | rot |
| `wrong_area` | Ticket gilt nicht in diesem Einlassbereich, oder die Person ist hier nicht eingeteilt | gelb |
| `checked_out` | (nur `check-out`) Auslass erfolgt | grün |
| `not_checked_in` | (nur `check-out`) Ticket ist nicht eingecheckt | gelb |
| `forbidden` | Person hat keinen Einlasszugriff (mehr) | rot, neu koppeln |

HTTP-Fehler gibt es nur bei Authentifizierung (401), Berechtigung oder Rate-Limit (403/429) und ungültigen Anfragen (400).

Hat die Ticketart eine Einlasskontrolle (Ausweispflicht, Mindestalter, Hinweis fürs Personal), enthält das Ergebnis zusätzlich `id_check` (bool), `min_age` (Jahre, 0 = keines) und `staff_hint` (fertiger Anzeigetext, z. B. „Ausweis prüfen · Mindestalter 18“). Die App muss `staff_hint` deutlich anzeigen; der Scan wird dadurch nicht abgelehnt.

**Erstnutzung:** Ein erfolgreicher Einlass enthält `admissions` (Anzahl Einlässe ohne rückgängig gemachte), `first_admission` (`true` beim ersten Einlass) und `first_checked_in_at`/`last_checked_in_at` (UTC, ISO 8601). Bei `already_checked_in`, `daily_limit_reached`, `entry_limit_reached` und `already_inside` liefert die Antwort dieselben Felder plus `last_checked_in_area` (Name des Einlassbereichs), damit die App „Bereits eingelassen um 19:42 · Nordeingang“ anzeigen kann. Der Web-Scanner zeigt sie so an.

`attendee_name` wird nur mitgeliefert, wenn die Einstellung „Teilnehmername im Scanner anzeigen“ (`scanner_show_attendee`) aktiv ist. E-Mail- und Zahlungsdaten liefert die API nie aus.

## 3. Einlassregeln und Einlassbereiche

Unter **Pass Captain → Einlassregeln** (Recht `wpcap_manage_events` mit Event-Zuweisung) wird pro Tickettyp festgelegt:

| Regel | Verhalten |
|---|---|
| Standard / `single` | einmaliger Einlass (Ticketstatus wird `checked_in`). Ohne eigene Regel gelten weiterhin die Tagesregeln des Termins (`occurrence_days`). |
| `daily` | einmal pro gültigem Tag; gültige Tage = konfigurierte Veranstaltungstage des Termins, sonst jeder Kalendertag von Beginn bis Ende |
| `multi_day` | einmal pro Tag an gewählten Daten bzw. Datumsbereichen |
| `multi_entry` | N Einlässe insgesamt |
| `reentry` | Einlass nur, wenn das Ticket draußen ist (Check-out dazwischen), optional maximal N Einlässe |

Optional: Einlassfenster in Minuten vor Beginn/nach Ende sowie tägliche Einlasszeiten (HH:MM). Alle Tage und Uhrzeiten gelten in der Zeitzone des Termins. Die Prüfung läuft atomar unter einer Zeilensperre auf dem Ticket.

**Einlassbereiche** (z. B. „Eingang Nord“, „VIP“) werden pro Event angelegt. Tickettypen können auf Bereiche beschränkt werden (dann nur dort und nicht ohne Bereich). Personal kann Bereichen zugewiesen werden und scannt für dieses Event dann nur dort. Geräte werden beim Koppeln einem Bereich zugeordnet. Der Web-Scanner bietet eine Bereichsauswahl und einen Umschalter Einlass/Auslass.

## 4. Offline-Betrieb

- Bei fehlender Verbindung speichert die App Scans lokal mit Code, `idempotency_key` und `scanned_at` (ISO 8601). Lokale Daten werden verschlüsselt gespeichert und nach erfolgreicher Synchronisierung gelöscht.
- Wieder online, ruft sie `POST /app/scan/sync` mit `{"scans":[{"code":"…","idempotency_key":"…","scanned_at":"…","direction":"in"}]}` auf (`direction`: `in` Standard, `out` für Offline-Check-outs; optional `area_id` bei Geräten ohne festen Bereich). Mehrfaches Hochladen ist durch den Schlüssel unschädlich. Einlassregeln (Tag, Fenster) werden zum Zeitpunkt `scanned_at` geprüft, aber nur, wenn dieser höchstens `offline_max_age_hours` zurückliegt (Standard 12 h, einstellbar unter **Einlassregeln → Offline-Scans**, 0 = immer Upload-Zeitpunkt); ältere Scans werden zum Upload-Zeitpunkt geprüft. Wird ein zurückdatierter Scan (mehr als 15 Minuten) eingelassen, wäre aber zum Upload-Zeitpunkt abgelehnt worden (z. B. zweiter Einlass auf ein Tagesticket, „gestern“ datiert), gibt es `ok: true` mit `conflict: true`, `review: "backdated_admission"` und einen Konflikt zur Prüfung. `scanned_at` wird nur bei Offline-Uploads berücksichtigt.
- `conflict: true` heißt: Das Offline-Gerät hat die Person eingelassen, der Server lehnt den Scan aber ab (`result` sagt warum). `double_admission: true` kennzeichnet echte Doppeleinlässe (`already_checked_in`, `daily_limit_reached`, `entry_limit_reached`, `already_inside`). Jeder Konflikt wird genau einmal unter **Pass Captain → Einlass-Konflikte** erfasst (`conflict_id`) – mit Ticket, beiden Geräten/Personen und Zeiten – und dort mit Klärung und Notiz abgeschlossen (Recht `wpcap_manage_events`, Audit-Log). Abgelehnte Offline-Check-outs sind keine Konflikte.
- Konflikte entstehen nur für Tickets von Events, für die das hochladende Gerät bzw. die Person Einlassrechte hat; Scans fremder Events (`wrong_event`, `forbidden`) und unbekannte Codes erzeugen keine Konflikte.
- Fehlerantworten enthalten nur `result`, Status und Meldung, keine internen IDs.
- Der Web-Scanner speichert Offline-Scans (Ticket-Code, Schlüssel, Zeit, Bereich, Richtung) im `localStorage` des Browsers, ohne Mengenbegrenzung, und lädt sie in Blöcken zu 100 hoch. Entfernt werden nur vom Server bestätigte Einträge (per `idempotency_key`). Die Warteschlange gehört dem angemeldeten WordPress-Benutzer (Schlüssel aus einem HMAC der Benutzer-ID, nicht die ID selbst). Öffnet ein anderer Benutzer den Scanner im selben Browser, werden fremde und ältere gemeinsame Warteschlangen gelöscht – auch noch nicht synchronisierte Scans. Vor einem Schichtwechsel deshalb synchronisieren. Einträge älter als 24 Stunden (Filter `wpcap_scanner_queue_max_hours`) werden mit Hinweis ohne Upload verworfen. Eine Abmeldung kann den Browserspeicher nicht selbst leeren: Auf geteilten Geräten nach Schichtende den Browser schließen bzw. die Website-Daten löschen.
- **Scanner-Geräte** zeigt pro Gerät zuletzt aktiv, letzte Synchronisierung und offene Konflikte.
- Der Web-Scanner synchronisiert über `POST /pass-captain/v1/admission/offline-sync` (Cookie + REST-Nonce) mit denselben Regeln; Einlass/Auslass mit Bereich über `/admission/check-in` und `/admission/check-out`.
- **Web-Scanner im Einlassbetrieb:**
  - Das Codefeld wird bei jedem Scan sofort geleert (nicht erst nach einem Erfolg), sodass ein Tastatur-Scanner (Wedge) den nächsten Code nie hinter den vorigen tippt. Ein Scan während der Sperrzeit nach einem Ergebnis (1,4 s) wird sichtbar verworfen („Scan verworfen – bitte erneut scannen“, Ton), das vorige Ergebnis bleibt stehen.
  - Eine Eingabe mit mehr als einem Ticket-Code (zwei verschiedene Codes oder eine Hex-Folge länger als 64 Zeichen) wird nicht gesendet. Auch der Server lehnt sie ab (`wpcap_ticket_ambiguous`, HTTP 400, `result: invalid_code`) statt den ersten Code einzulassen; `Checkin::normalize_code()` liefert dafür `''`.
  - Jede Anfrage hat ein Zeitlimit von 5 s (AbortController). Ohne Antwort folgt die Offline-Entscheidung („Nach Sichtprüfung einlassen“). Der Offline-Eintrag übernimmt den `idempotency_key` des Online-Versuchs: Hat der Server den Scan doch erhalten, erkennt die spätere Synchronisierung ihn als denselben Scan statt als Doppeleinlass-Konflikt.
  - Vor dem Angebot „Nach Sichtprüfung einlassen“ sucht der Scanner den Code in der eigenen Offline-Warteschlange. Wurde er auf diesem Gerät schon offline eingelassen (und seitdem nicht ausgelassen), erscheint rot „Auf diesem Gerät bereits offline eingelassen um …“. Ein Doppeltipp auf den Knopf speichert den Scan nur einmal.
  - Als Ablehnung („KEIN EINLASS“) gilt nur ein JSON-Fehler des Plugins mit `wpcap_*`-Code. Jede andere Antwort – WAF-, Proxy- oder Login-Seite, Antwort ohne JSON, fremder Fehlercode (`rest_*`), 5xx, `retry`/`busy` oder eine 2xx-Antwort ohne `status: checked_in`/`checked_out` – erscheint als gelbe Systemwarnung „Systemfehler – das Ticket wurde nicht geprüft“. Die Offline-Synchronisierung entfernt Einträge nur nach einer JSON-Antwort mit `results`.
- **Offline-Betrieb kann keine globale Einmaligkeit garantieren** (siehe CONCEPT.md, Abschnitt 7). Für streng begrenzte Einlässe nur online scannen.

## 5. Empfehlungen für App-Umsetzung

- **Scannen:** native Kamera-APIs (iOS AVFoundation/VisionKit, Android ML Kit/CameraX). Gleichen Code innerhalb von ca. 5 s ignorieren.
- **Feedback:** grün, rot oder gelb plus Ton bzw. Vibration. Die Texte kommen aus `message` und sind übersetzt (Deutsch/Englisch).
- **Uhrzeit:** `server_time` aus `/app/me` gegen die Gerätezeit prüfen und bei mehr als 2 Minuten Abweichung warnen, weil `scanned_at` für Offline-Scans zählt.
- **Ablauf und Widerruf:** Bei 401 das Token verwerfen und die Kopplung neu starten.
- **Kein Speichern von Personendaten** über die Sitzung hinaus.

## 6. Sicherheit

- Nur HTTPS verwenden, zusätzlich Certificate Pinning empfehlen.
- Tokens sind kurzlebig (Standard 24 h) und eventgebunden. Wird ein Gerät verloren, unter **Scanner-Geräte** sofort widerrufen.
- Jeder Ein- und Auslass wird mit Person, Gerät, Bereich, Quelle (`web`/`app`/`offline`), Server- und Gerätezeit protokolliert (`wpcap_checkins`, Audit-Log).
- Verliert eine Person ihre Rolle oder Event-Zuweisung, verliert auch ihr Gerät sofort den Zugriff.

## 7. Versionierung und Ausbau

- Die Version steht in `/app/me` als `api_version` (aktuell `1.1`: Check-out, Bereiche, erweiterte Statistik und Ergebnis-Codes; abwärtskompatibel). Änderungen, die bestehende Apps brechen, bekommen einen neuen Pfad (`/app/v2/…`). Neue Felder sind jederzeit möglich, Apps müssen unbekannte Felder ignorieren.
- **Geplant (nicht umgesetzt):**
  - Offline-Prüfpaket: signierte Liste gültiger Ticket-Hashes pro Termin für echte Offline-Validierung.
  - Push-Benachrichtigung bei Gerätewiderruf.
  - Suche nach Teilnehmenden mit eigenem Recht.
  - Badge-Druck.
  - Gerätebindung per App Attest / Play Integrity.
