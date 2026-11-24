# Offline-Scanner (PWA)

Module `OfflineScanner` (`src/Modules/OfflineScanner.php`, code in `src/OfflineScanner/`, app in
`assets/offline-scanner/`). Lets a paired scanner device admit guests without network access, based
on a **signed verification package** per occurrence, and upload the scans later through the existing
offline sync (`POST /app/scan/sync`, see [MOBILE-API.md](MOBILE-API.md)).

## Overview

1. **Opt-in per event** (admin: *Pass Captain → Offline-Scanner*): off by default.
2. **Pair a device** with the normal pairing-code flow (*Scanner-Geräte*), entering the code in the PWA.
3. **Assign the device** to the event (all occurrences) or to single occurrences.
4. The PWA downloads the signed package, verifies it, and stores it in IndexedDB.
5. Scans are checked online first. Without network they are decided locally and queued.
6. When the device is back online, the queued scans are synced. Conflicts appear in the app and under
   *Einlass-Konflikte*.

## Kasse ohne Netz (Entscheidung E-13)

Ohne Netz sind **nur Einlässe** möglich: Check-ins mit dieser Scanner-Web-App (signiertes Paket, Scans
werden später synchronisiert). Die **Kasse braucht für Verkäufe, Stornos und Erstattungen immer eine
Netzverbindung**; es gibt keinen Offline-Verkauf und keine Offline-Erstattung. Gründe:

- **Platzbestand:** Ein Verkauf reserviert den Restbestand (Kontingente, Sitzplätze) auf dem Server unter
  Sperre. Ein offline erfasster Verkauf könnte bereits verkaufte Plätze ein zweites Mal vergeben.
- **Zahlungsbestätigung:** Kartenzahlungen und Terminal-Abgleich brauchen die Antwort des Servers bzw.
  des Zahlungsanbieters, bevor Tickets ausgestellt werden.
- **Fiskalbelege:** Mit dem Modul Fiskalisierung wird jeder Verkauf erst nach dem bestätigten Beleg des
  Anbieters gebucht (Ausfallregel `stop_sale`); ein Offline-Beleg ohne Signatur wäre unzulässig.

Die Kassenoberfläche (`assets/boxoffice/pos.js`) zeigt deshalb ohne Verbindung einen Hinweis und sperrt
die Schaltflächen für Verkauf, „Nachbuchen“, Storno und Auszahlung, bis die Verbindung wieder steht.
Nichts wird zwischengespeichert oder später automatisch gesendet. Ein Barverkauf ohne Netz wäre ein
eigenes späteres Vorhaben mit lokaler Bestandsreservierung, Konfliktabgleich und Fiskalregeln.

## Event rules (`wpcap_offline_events`)

| Setting | Default | Meaning |
|---|---|---|
| `enabled` | off | Only opted-in events deliver packages (`403 wpcap_offline_disabled` otherwise). |
| `max_offline_hours` | 12 (1–72) | Package lifetime (`expires_at = issued_at + hours`). Uploaded offline scans older than this are admitted, but recorded as an open conflict `offline_expired`. |
| `duplicate_policy` | `review` | Rule for a ticket admitted offline on two devices. `review`: the later upload becomes an open conflict. `first_sync_wins`: the first upload counts; a later duplicate of the **same device** (or, without devices, the same user) is still recorded but resolved automatically as `duplicate_scan` (`resolved_by = 0`, audit `admission_conflict_auto_resolved`). The same code admitted on **another device** (e.g. a shared screenshot) stays an open conflict with `cross_device: true`; staff resolve it, e.g. as `duplicate_admission` („Doppel-Einlass auf verschiedenen Geräten“). "Einlass-Konflikte" shows the count of cross-device double admissions (open / total / confirmed) and the tickets with the most check-in undos. |
| `include_attendee` | off | Ships attendee names, but only while the global setting `scanner_show_attendee` is on as well. Never e-mails. |

The Admission setting `offline_max_age_hours` still decides at which time an offline scan is
evaluated: the device time, or the upload time for older scans.

## Device assignment (`wpcap_offline_assignments`)

A device gets a package only if **all** of these hold:

- it is paired and active (not revoked, not expired),
- the event is among its paired events,
- it has an assignment row for the event, with `occurrence_id` 0 (all occurrences) or the exact occurrence,
- the event is opted in,
- the device user has `wpcap_checkin` access to the event.

Otherwise the server returns `403 wpcap_offline_not_assigned`. Revoked devices get `401`. The PWA
deletes stored packages that the server no longer lists or refuses with 403. On `401` (or an error code
`…device_revoked`/`…device_unauthenticated`) it wipes the device, see *Revoked token* below.

The admin shows the package expiry per device (A688): *Scanner-Geräte* lists "gültig bis …" (the next
expiry of a still valid package: `last_package_at` + the event's `max_offline_hours`, the same moment as the
package's `expires_at`), "abgelaufen am …" or "kein Paket geladen"; the device table of an event under
*Offline-Scanner* has a column "Paket gültig bis" (`Assignments::package_expiry()` / `package_status()`).

## REST (device bearer token, namespace `pass-captain/v1`)

| Route | Description |
|---|---|
| `GET /app/offline/keys` | Trusted public keys: `{keys:[{key_id, alg:"Ed25519", public_key (base64), status: active\|retired, created_at, valid_until}], server_time}` |
| `GET /app/offline/occurrences` | Occurrences this device may hold packages for, including their rules. |
| `GET /app/offline/package?occurrence_id=&since=` | Signed package: a full package, or a delta when `since` is a valid cursor. |

Public (no token, the pairing screen needs it before a device token exists; it only contains UI texts):

| Route | Description |
|---|---|
| `GET /app/offline/i18n?locale=` | Translation table of the PWA: `{v, locale, lang, strings, supported}`. `strings` maps the German source text (the msgid in `languages/pass-captain.pot`) to its translation; `locale` is the WordPress locale served (e.g. `fr_FR`), `lang` its BCP 47 tag for `<html lang>` and `Intl` (e.g. `fr-FR`), `supported` the BCP 47 tags the server can serve. Optional `locale`: the device's preferred languages, comma separated, best first (the PWA sends `navigator.languages`, e.g. `fr-CH,fr,en`). The first one with a translation wins – exact locale first, then the language (`fr` → `fr_FR`); without a match the site locale is used. Supported: German (source texts), every bundled translation in `languages/`, installed language packs and the site locale (filter `wpcap_offline_scanner_locales`). Sent with `Cache-Control: no-cache`. Filters: `wpcap_offline_scanner_locale` (site locale), `wpcap_offline_scanner_strings` (table). Also `brand` (`{name, logo, custom}`, white label via `wp_pass_captain_brand_name_v1`/`_logo_v1`, context `offline_scanner`): the app uses the name in the page title and, when `custom`, shows the logo (same origin only, CSP) and links the manifest below. |
| `GET /app/offline/manifest` | Public web app manifest with the brand name (`<name> Scanner`), absolute start URL and the bundled icons. Only linked by the app when a brand is set; otherwise the static `manifest.json` stays in use. |

Existing responses are also extended:

- `POST /app/pair` adds `offline: {keys, package_format, package_formats}`, which delivers the public keys at pairing (`package_formats`: the supported payload versions, `[1, 2]`).
- `GET /app/me` sets `features.offline_packages = true`; its `permissions` (`undo`, `check_out`, see
  [MOBILE-API.md](MOBILE-API.md)) are read by the PWA on every online refresh and kept in IndexedDB (`kv.perms`).
- `POST /app/scan/sync` result rows gain `offline_rules`, `auto_resolved`, `cross_device` (double admissions), `offline_expired` and `conflict_status`.
- A result row with `retryable: true` (`result: "retry"`) was not booked because of a transient server
  error (A345). The PWA keeps such scans as "wartet auf Sync" (code kept) and uploads them again with the
  same idempotency key; a batch in which nothing could be booked does not trigger an immediate re-sync.
- Codes in a package fetched by an assigned device during the last 72 hours cannot be rotated by the
  buyer (portal resend/PDF, transfer, attendee change; A593), so a device never admits a code the server
  no longer knows. Staff actions (reissue, admin resend, printing) still rotate.

### Package response

```json
{ "alg": "Ed25519", "key_id": "…16 hex…", "payload": "<JSON string>", "signature": "<base64>",
  "kind": "full|delta", "cursor": "<epoch>.<version>", "count": 3, "format": 1, "expires_at": "…" }
```

`GET /app/offline/package?occurrence_id=&since=&format=2` – `format=2` asks for the compact payload
(below); without it existing clients keep getting format 1. When the request accepts gzip
(`Accept-Encoding: gzip`), zlib is available and PHP does not compress the output itself, the body is
sent with `Content-Encoding: gzip` (from 1 KB on); browsers inflate it transparently and the signed
payload string is unchanged.

The signature covers the exact UTF-8 bytes of `payload`. Clients must verify the signature first,
then parse `payload`, and use only the signed fields. The fields outside `payload` are convenience copies.

Signed payload (`v` = package format version 1):

```json
{ "format": "wpcap-offline-package", "v": 1, "kind": "full", "site": "https://…/",
  "device_id": "<public device id>", "key_id": "…",
  "event": {"id": 1, "name": "…"}, "occurrence": {"id": 2, "starts_at": "…", "ends_at": null, "timezone": "Europe/Vienna", "status": "scheduled", "doors_open_at": null},
  "issued_at": "…", "expires_at": "…", "since": null, "cursor": "a1b2c3d4e5f60718.7",
  "rules": {"max_offline_hours": 12, "duplicate_policy": "review", "attendee_names": false},
  "device_area_id": null, "day_cutoff_hour": 0, "hash": "sha256",
  "ticket_types": [{"id": 5, "name": "Standard", "areas": [], "policy": "single", "multi": false, "own_policy": false, "max_entries": 1, "valid_days": null, "id_check": false, "min_age": 0, "hint": ""}],
  "areas": [{"id": 3, "name": "Eingang Nord"}],
  "permissions": {"undo": true, "check_out": true},
  "checkout": {"staff_area_ids": [], "reentry_after_checkout": false, "max_reentries": 0},
  "tickets": [{"h": "<sha256(code) hex>", "s": "valid|checked_in|revoked", "t": 5, "n": "Name (optional)"}] }
```

Format 2 (`"v": 2`, compact, about 35 % of format 1) replaces `tickets` with

```json
  "hash_prefix": 32,
  "rows": [[5, 1, "<32 hex><32 hex>…"], [5, 2, "<32 hex>…"], [0, 0, "<32 hex>…"]],
  "names": {"<32 hex>": "Name"}
```

Each row is `[ticket type, status digit, concatenation of the first 32 hex digits of every ticket hash
with this type and status]`; status digits: `0` revoked (type `0`), `1` valid, `2` checked_in. `names`
only exists with attendee names (opt-in per event, see `include_attendee`). 128 bits of the hash
identify a ticket for this purpose as safely as the full hash (a forged code would need a 128-bit
preimage); the app keys its index by these 32 digits for both formats.

`max_entries` and `valid_days` (the service days of `daily`/`multi_day` types, `null` = no day
restriction) and `day_cutoff_hour` feed the app's local entry counters (see below).

Rights and check-out rules (both formats, every full package and delta; older apps ignore them):

- `permissions` – what the device's user may do: `undo` (capability `wpcap_undo_checkin`), `check_out`
  (role rule `checkout_roles`, `Checkin::checkout_allowed()`; administrators always). The PWA hides
  „Rückgängig“ and the direction switch without the right.
- `checkout` – the rules of an offline check-out: `staff_area_ids` (areas the user is assigned to for this
  event, empty = all areas, always empty for administrators; rule 2 of `Admission\Areas`),
  `reentry_after_checkout` and `max_reentries` (re-entry of ticket types without own admission rule).
  A package without `checkout` (older server) offers no check-out in the PWA.
- `ticket_types[].own_policy` – the type has its own admission rule (then the re-entry setting does not
  apply, like on the server).

Measured with 50,000 tickets of one occurrence (`Package::build`, no names): format 1 payload 4.34 MB,
HTTP body 4.82 MB, gzip 1.83 MB; format 2 payload/body 1.53 MB, gzip 0.86 MB. A compact delta after
20,000 check-ins: 0.61 MB, gzip 0.35 MB.

What the package contains:

- **Full packages** contain the `valid` and `checked_in` tickets.
- **Deltas** (`since` = the previous cursor) contain every changed hash, including `revoked`. A hash
  becomes `revoked` when its ticket is revoked, when its code is reissued (the old hash goes away), or
  when the occurrence or event is cancelled. A hash that went away because the ticket got a new code
  carries `"r": "replaced"` (A543); the app then shows "Code ersetzt" instead of "Widerrufen".
- An unknown epoch or a future cursor gives a full package.
- Any older cursor of the same epoch is a valid `since`: after 30 deltas the app asks with
  `since=<cursor of its full package>` and gets one compact delta with all changes since then, instead
  of downloading the full package again.
- Every package (including an empty delta) renews `expires_at`.
- Hashes are `sha256` of the 64-hex ticket code (the same as `wpcap_tickets.token_hash`).
- Ticket codes, e-mails and order data are never included.
- `ticket_types[].id_check`, `min_age` and `hint` carry the ID/age check of the ticket type; the app
  shows `hint` (e.g. "Ausweis prüfen · Mindestalter 18") with every result of such a ticket.
- `occurrence.doors_open_at` is the doors-open time ("Einlass ab", ISO 8601) or null; the app refuses
  scans before it with `outside_entry_window`, like the server.

Change tracking works without core hooks. `wpcap_offline_entries` keeps a snapshot (hash, status,
type, name fingerprint, version) per occurrence. Each request compares it with the live tickets, at
most every 10 s (filter `wpcap_offline_refresh_interval`). `wpcap_offline_state` holds the epoch and
the version.

## Keys

Keys are stored in the option `wpcap_offline_keys` (not autoloaded):

- Ed25519 key pairs are generated with libsodium on first use.
- The secret key is encrypted with `sodium_crypto_secretbox`. The encryption key is derived from the
  constant `WPCAP_OFFLINE_SIGNING_KEY`, or from the WordPress auth salt if the constant is not set.
- The secret key is never returned by any endpoint.
- The key id is the first 16 hex characters of `sha256(public key)`.

**Rotation** (admin, `manage_options`) works like this:

- A new key is created and becomes active.
- The old secret key is wiped immediately.
- The old public key stays published as `retired` until `valid_until`, then it is pruned.
  `valid_until` is the latest of: the expiry of the last package it signed, and now + the longest
  offline duration of any enabled event.

If the salt changes and the secret can no longer be decrypted, a new key is generated automatically.

**Concurrency** (A629): creating the first key, rotating and extending a key's grace run under the
database lock `DbLock::acquire('offline_keys')` (name unique per installation, A364) and re-read the key option inside it, so a
rotation is never overwritten by a process that read the keys before (and two first uses create one
key, not two). Signing only reads the keys; the latest expiry signed per key is kept in the separate
option `wpcap_offline_keys_expiry` (raised under the same lock). A package signed with a key that was
rotated away in the meantime extends that key's `valid_until` to the package expiry. If the lock is not
available within 10 seconds, signing/rotation fails with `offline_key_busy` and nothing is changed.
Audit actions: `offline_key_rotated`, `offline_rules_saved`, `offline_device_assigned` /
`offline_device_unassigned`.

## Signed QR codes (PC1)

When the setting `signed_qr` is on, ticket QR codes carry
`PC1.<base64url(JSON {t, o, v, k, c})>.<base64url(Ed25519 signature)>` instead of the 64-hex code (format,
key handling and interop decision: [SECURITY.md](SECURITY.md#signierte-qr-codes-pc1)). The QR signing key is
separate from the package signing key; its public keys are published as `qr_keys` next to `keys` in
`GET /app/offline/keys` and in the pairing response (`offline.qr_keys`).

Offline pre-validation in the PWA (`verifySignedQr()` / `decideSigned()` in `app.js`):

1. Ed25519 signature over the ASCII bytes `PC1.<claims>` with a trusted QR key (retired keys only until
   `valid_until`; unknown key id = `invalid_signature`).
2. Claim types and the validity window at the device time (corrected by the server clock offset, never
   earlier than the last known server time); outside = `out_of_window`. The window is the signed `v`,
   widened to the current dates of the package's occurrence (same `o`): from `min(v[0], start − 365 days)`
   to `max(v[1], (end or start) + 1 day)`. A QR code sent before the occurrence was moved therefore stays
   valid for the new date (audit QA-2). Multi-day admission policies are only known to the server; the
   PWA uses the occurrence end.
3. `o` must be the package's occurrence (`wrong_occurrence`).
4. The code version `c` (first 16 hex of the code hash) is looked up in the package index. A reissued
   ticket has a new hash, so a QR of the old code is `unknown` (or `revoked` once the snapshot lists the old
   hash as revoked). Then the usual local rules apply (revoked, already used, area).

Online the server decides (signature, code version, window, ticket state). The server checks the window
the same way (`SignedQr::in_window()` with the ticket's current row): the signature proves ticket and
occurrence, the window is `[min(signed start, current start), max(signed end, current end)]`, where the
current window is `SignedQr::window()` (a year before the start until a day after the last valid time,
filter `wpcap_signed_qr_window`). Offline admissions upload the
signed code unchanged through `/app/scan/sync`; the server verifies it again. Classic codes keep working
everywhere, whatever the setting.

## PWA (`assets/offline-scanner/`)

| File | Contents |
|---|---|
| `index.html` | App shell with the German source texts (`data-i18n*` attributes); translated at runtime. Strict CSP with `connect-src 'self'`. |
| `app.js` | App logic. |
| `app.js` | App logic; all visible texts go through `t()`. |
| `app.css` | Styles. |
| `sw.js` | Service worker that caches the app shell. API calls are never cached. Only navigations to the app itself (scope root or `index.html`) are served from and stored as `index.html`, and only `text/html` responses are stored; other navigations inside the scope are network-only. |
| `manifest.json`, icons | Makes the app installable. Locale-neutral on purpose (product name only, no `lang`/description): a static file cannot follow the device language. |
| `../vendor/tweetnacl/` | tweetnacl-js 1.0.3 (Unlicense), used for signature verification; origin and checksum in `SOURCE.txt` (ships with the ZIP, `*.md` does not). |
| `../vendor/jsqr/` | Camera fallback when `BarcodeDetector` is unavailable. |
| `i18n.js` | Static translation catalogs (German source, English); works offline. |

- **Served from the site (A286):** the app is not opened from the plugin's asset URL but from
  `home_url('/wpcap-offline-scanner/app/')` (plain permalinks: `/index.php/wpcap-offline-scanner/app/`),
  where `OfflineScanner\Pwa` serves exactly the shell files above (and `…/vendor/` for tweetnacl/jsQR) with
  `Cache-Control: no-cache` and `nosniff`. That keeps app, service worker and REST API on one origin even
  when plugin assets are delivered from a CDN domain (the app only accepts a same-origin API). Apps installed
  from the old asset URL keep working where that URL is on the site's own domain.
- **Start:** open the app from the admin button. The link passes `?api=<rest_url>`, which is stored.
- **One store per site (A473):** the app is served from the plugin URL, which all sites of a
  (subdirectory) network share – one origin, one IndexedDB. Pairing token, public keys, packages and
  the scan log are therefore kept in one database per API base (`wpcap-offline-scanner@<api>`); each
  site of the network needs its own pairing. A start without `?api=` (home screen icon) opens the
  site used last (`localStorage` key `wpcap-offline-scanner-api`); open the app from the other site's
  admin button to switch. A pairing QR code of another site pairs in that site's store. A pairing
  made before keeps its database (`wpcap-offline-scanner`, its stored `api`) and only serves that
  site. Unpairing clears the store of the current site only. Service worker cache: `v6`.
- **Language:** the link also passes `?lang=` (WordPress user locale). The app remembers it on the
  device for offline starts and otherwise falls back to the browser language, then German. Dates use
  the matching locale. Add a language by adding a catalog to `i18n.js` (keys = German source text).
  Only **same-origin** API bases are accepted, and only the app API itself (A649): the path must end
  with `/wp-json/pass-captain/v1/app/` (or the legacy namespace `/wp-json/wp-pass-captain/v1/app/`),
  or the base is the plain permalink form `…/?rest_route=/pass-captain/v1/app/` without further
  parameters. Anything else in `?api=`, in the pairing QR or in storage is ignored (the app derives
  the base from its own location instead), so the device token never goes to another path of the
  same host. Sites with a changed REST prefix get the `?rest_route=` form in the admin link and the
  pairing QR (`Mobile\Devices::api_base()`). HTTPS (or localhost) is required for the service
  worker and WebCrypto.
  It is only used while the device is **not paired**; a paired device keeps its stored API base and
  ignores the parameter (the parameter is removed from the address bar either way). Accepted API
  bases (parameter, stored value and the `api` of the pairing QR): **same origin** and exactly the
  plugin's app namespace, either a REST path ending in `/pass-captain/v1/app/` or
  `…?rest_route=/pass-captain/v1/app/` as the only query parameter (both forms also with the
  legacy namespace instead of `pass-captain/v1`: `wp-pass-captain/v1`); anything else falls back to the
  base derived from the plugin URL. HTTPS (or localhost) is required for the service worker and
  WebCrypto.
- **Language:** on every online start the app loads `GET /app/offline/i18n` with the device languages
  (`navigator.languages`) and keeps the table in IndexedDB, so it stays translated offline. It sets
  `<html lang>` and formats dates with `Intl` in the served language. Without a table (first start
  offline) the German source texts are shown.
- **Pairing:** enter the pairing code, or paste the JSON payload of the pairing QR. The device token
  and public keys are stored in IndexedDB.
- **Packages:** the app stores one full package plus chained deltas per occurrence (at most 30, then
  it fetches a new full package). Every document is re-verified on each start:
  - the signature with the known key (retired keys only until `valid_until`),
  - `format`/`v`, `key_id` and `device_id` (packages are bound to the device),
  - the chain (`delta.since === previous cursor`),
  - no rollback: the app stores the newest accepted `cursor` and `issued_at` per occurrence (IndexedDB
    `kv.hw`) and refuses a package with an older `issued_at` or a lower cursor version of the same epoch.
  - Expired packages (they may carry attendee names) are deleted at start and every 30 s; the next
    online refresh loads a new one.
- **Scanning:**
  - Every scan shows the neutral state „Prüfe …“ at once, so the previous guest's result is never
    visible during a check. A result stays about 4 s, then the app shows „Bereit“ again. The same code
    is ignored for 10 s (a QR code held in front of the camera). A rejection for a code this device
    admitted within the last 5 minutes (server „already used“ or a local limit) is shown yellow as
    „Eben hier eingelassen (vor X s)“ instead of red.
  - Feedback: vibration and a short WebAudio tone (high = admitted, double = warning, low = rejected),
    switchable with the „Ton an/aus“ button (kept in IndexedDB `kv.sound`). The camera uses continuous
    autofocus and offers a torch switch where the browser supports them.
  - Online, the app calls `POST /app/scan/check-in` with an idempotency key (5 s timeout).
  - On a network error, a timeout or 5xx it decides offline. It normalizes the code like the server,
    computes its SHA-256 with WebCrypto and looks the hash up in the package.
  - Only a scan result of the plugin is shown green or red (`ok: true`, or `ok: false` with a
    `wpcap_*` code). Any other answer – a WAF, proxy or login page, an answer that is no JSON object,
    an unknown error code or a retryable server error – is a yellow “System error – ticket not
    checked” warning, never “no entry”.
  - An input with more than one ticket code (two codes, or a hex run longer than 64 characters,
    e.g. codes typed behind each other) is refused without a check, like on the server.
  - The camera ignores a code for as long as it stays in view: the 3 s duplicate window restarts
    with every sighting (sliding, like the web scanner), so a guest just admitted never gets
    “already used” a moment later. A manual entry is always checked.
  - On a network error, 5xx (including the database retry, HTTP 503 with `result: "retry"`) it decides
    offline. It normalizes the code like the server, computes
    its SHA-256 with WebCrypto and looks the hash up in the package. Signed QR codes (PC1) are
    verified locally first and matched by code version (see above).
  - A ticket is refused offline when it is unknown, revoked, already used (single entry: package
    status or the local log), in the wrong area (the type's area restriction against the device's or
    the selected area), or when the package is missing or expired.
  - Multi-entry types are admitted offline. The server checks their limits at sync.
  - **Help code** (8 characters, e.g. `K7QM-4XWA`, printed below the QR code): typed into the manual
    field when the QR code cannot be read. It is checked **online only** (the package holds no help
    codes, see docs/SECURITY.md); offline the app asks for the QR code or the long code instead.
  - Result: large coloured box, distinct tones (WebAudio, “Sound on/off” per device) and vibration
    patterns; online results show “first admission” or “already admitted at …” from the server.
  - **Full-screen result (A548):** every result is also shown full screen in large type with icon,
    the time (hh:mm:ss) and the short code of the scanned ticket (last 6 characters of its hash, e.g.
    `…3F9A2C`) – green for 2.5 s, yellow 5 s, red 8 s, or until tapped. The result box then returns to
    “Bereit” (green after 6 s, otherwise after 15 s; not while an undo form is open), so a previous
    verdict is never read as the current one. The overlay never takes focus; the result box stays the
    live region for screen readers.
  - **Search (A544):** “Ticket suchen” for guests without a readable code. Online the app calls
    `GET /app/search` (order number always; attendee name and e-mail only as the server's PII rules
    allow) and checks a hit in with `POST /app/scan/check-in` and `ticket_public_id` (same rules as a
    scan, logged as manual check-in). Offline it searches the attendee names in the package – only
    when the event ships names (`rules.attendee_names`) – decides like a scan and uploads the
    admission with `ticket_hash` instead of the code.
  - **Counter (A546):** above the result the app shows “Eingecheckt: 120 / 300” for the selected
    date: online the server value (`admitted`/`admission_total` of `/app/occurrences/{id}/stats`, at
    most every 10 s, own first admissions counted at once) plus this device's offline admissions not
    uploaded yet; without a server value the package count (“laut Prüfpaket”: tickets used in the
    package or admitted on this device). An old server value shows “Stand hh:mm”.
  - **Undo (A547):** for 30 s after an admission the app offers “Rückgängig” – only when the device's user
    holds `wpcap_undo_checkin` (`permissions.undo` of `/app/me`, else of the package; unknown, e.g. an
    older server, = offered and the server decides). Without the right there is no undo, also not for an
    offline admission that is not uploaded yet. If the server still refuses (`wpcap_undo_capability`, as
    `ok: false` or HTTP 403), the app shows the server's message (for a 403 without a readable body: „Du
    darfst keine Check-ins zurücknehmen. Bitte die Einlassleitung fragen.“) and offers no undo from then
    on. A reason is required
    (four presets or own text). An online admission – or an offline one that is already uploaded –
    is taken back with `POST /app/scan/undo` (`ticket_public_id`, `reason`, area of the scan); the
    server checks device events, area binding and refunds (`Checkin::undo_guard()`). An offline
    admission that is not uploaded yet was never booked: it is marked `undone` in the local log (with
    the reason), never uploaded, and the ticket can be admitted again on this device. While a sync is
    running the undo waits (“Synchronisierung läuft”).
  - Multi-entry types are limited by local counters per hash from the device's own scan log (online
    and offline admissions of the last day; pending scans always): `daily`/`multi_day` once per service
    day (occurrence timezone and day cutoff hour, `valid_days`), `multi_entry` up to `max_entries`,
    `reentry` not while the ticket is inside (inside = admitted on this device and not checked out on this
    device since). Entries on other devices are only known to the server; it checks the limits again at sync.
  - **Re-entry after check-out:** with the setting `reentry_after_checkout` (package `checkout`) a used
    single-entry ticket of a type without own admission rule is admitted again offline when this device
    checked it out after its last entry here, up to `max_reentries` re-entries counted from this device's
    entries (shown with „Wiedereinlass nach Check-out – Prüfung beim Sync“). The server checks again at
    sync; a refused re-entry becomes an admission conflict like any other offline admission. Known limit:
    a refunded ticket that was checked in keeps status `checked_in` in the package, so the device cannot
    tell; the server refuses the re-entry at sync (conflict).
- **Check-out (Auslass) offline:** the switch „Einlass / Auslass (Check-out)“ appears only when the selected
  package carries `checkout` and the user may check out (`permissions.check_out` of `/app/me`, else of the
  package). In check-out mode the idle box shows „Bereit (Auslass)“. Same rules as online
  (`Checkin::check_out()`):
  - Online the app calls `POST /app/scan/check-out` (code, idempotency key, occurrence, area); a help code
    is checked out online as well. A refusal `wpcap_checkout_forbidden` hides the switch from then on.
  - On a network error, timeout or outage it decides offline: package missing/expired, no right
    (`checkout_forbidden`), unknown hash and an area the user is not assigned to (`staff_area_ids`) are
    refused. Inside is only known for this device: a ticket this device admitted and did not check out
    since is checked out at once („Ausgecheckt (offline)“); a ticket this device already checked out after
    its last entry here is refused („Nicht eingecheckt · Auf diesem Gerät bereits ausgecheckt“). **Every
    other known ticket cannot be decided offline safely** (entries on other devices, revoked tickets that
    are still inside): it is queued as „Check-out vorgemerkt – Prüfung beim Sync“ and the server decides.
  - Accepted offline check-outs are logged like admissions (code kept until uploaded, „wartet auf Sync“)
    and uploaded with the batch sync as `direction: "out"`. The server applies the online rules (inside,
    role, area binding, event/date scope); a refused check-out is **no** admission conflict, the log shows
    „Check-out abgelehnt: <reason>“. Uploading again replays the first answer (idempotency key).
  - Check-outs are not offered for undo, do not count in the check-in counter, and the search
    („Einchecken“) always admits.
- **Package download:** 120 s timeout (full packages of large events on weak networks) with the
  received kilobytes shown in the package box; format 2 is requested.
- **Log and sync:** every scan is logged locally with its idempotency key. Offline admissions are
  uploaded through `POST /app/scan/sync` (batches of 200) when the device is online, on reconnect and
  every 30 s. The ticket code is kept only until the upload succeeded. Server conflicts, including
  auto-resolved ones, are listed in the app. The log is shortened after each sync, at start and every
  30 s: synced entries older than 24 h are removed and at most 500 synced entries are kept. Pending
  (not yet uploaded) scans are never removed.
- **Revoked token:** when the server answers `401` (keys, occurrences, package, scan or sync), the app
  deletes the device token, device id, package and QR keys, all packages, the scan log (including the
  ticket codes of pending scans, which the server would no longer accept from this device), the clock
  offset and the rollback markers, then shows the pairing screen. Only the translations and the API base
  stay.
- **Clock:** the app takes an offset from the server time of the keys endpoint, to limit
  manipulation of the device clock. Package expiry is still enforced on the device, so a rooted
  manipulation of the device clock, and keeps the last known server time (`server_time` of the keys
  endpoint, `issued_at` of verified packages; IndexedDB `kv.server_time`) as a lower bound for its
  clock: setting the device clock back does not extend a package. Package expiry is still enforced on
  the device, so a rooted device can bypass it. The server then flags old scans (`offline_expired`).
- **Time zone (A516):** start times, package dates, earlier admissions and logged scans are shown in
  the occurrence's time zone (`occurrence.timezone` of the package, `timezone` of the assignment
  list), not the device's; the zone abbreviation is added when it differs from the device zone. The
  web scanner does the same with the `timezone` of the ticket answer (also sent with refusals that
  carry an admission history).
- **Camera:** stopped (all tracks ended) when the app is hidden or left (`visibilitychange`,
  `pagehide`) and after a 401 wipe.
- **Diagnostics:** `window.wpcapOffline` exposes helper functions and a view of the state without the
  device token.

Ship changed shell files by bumping `CACHE` in `sw.js` (currently `wpcap-offline-scanner-v11`). Assets are also refreshed in the background
(stale-while-revalidate).

The app runs edge to edge (`viewport-fit=cover`); the top bar, the content and the full-screen result keep
clear of the notch, rounded corners and the home indicator with `env(safe-area-inset-*)` padding (A398).

## Security notes

- A package lets a device recognize valid ticket **hashes**, not forge tickets: the codes cannot be
  derived from the hashes (256-bit random codes).
- Revoking a device makes its token invalid (`401`). As soon as the device reaches the server again it
  wipes its local data (see *Revoked token*). While it stays offline, a package it already holds stays
  usable until it expires. Keep `max_offline_hours` short.
- Every offline admission is re-checked at sync. Double admissions are never silently dropped: they
  are always recorded as admission conflicts.

## Tests

- `tests/integration/modules/zzzzz_x4_signed_qr.php` covers signed QR codes on the server (valid,
  tampered, unknown/foreign key, rotation with grace and revocation, window, reissued and revoked
  tickets, classic codes, mobile API and offline sync); `tests/js/offline-scanner.test.js` covers the
  offline verification in the PWA. `tests/integration/modules/zzzzz_y7_frontend_scanner.php` covers a
  signed QR after moving the occurrence by 30 days (QA-2).
- `tests/js/offline-scanner.test.js` also covers the 401 wipe, pruning of expired packages and of the
  log, the `?api=` rules, rollback protection and the server time floor; `tests/js/offline-sw.test.js`
  the service worker cache rules.
- `tests/js/offline-scanner-checkout.test.js` covers undo only with the right (and readable refusals),
  the direction switch, the offline check-out decision, queueing and upload with `direction: "out"`, and
  re-entry after a local check-out; `tests/integration/modules/zz_offline_checkout.php` the package
  fields `permissions`/`checkout`/`own_policy`, `/app/me permissions.check_out` and uploaded check-outs
  (inside, role, area, replay, re-entry).
- `tests/integration/modules/offline_scanner.php` covers opt-in, assignment, signatures (including
  `sodium_crypto_sign_verify_detached`), deltas with revocations, expiry, rotation and grace, and the
  sync rules.
- A Playwright check against a real WordPress install drove the end-to-end flow: pairing, admin
  opt-in and assignment, online and offline scans, an offline reload through the service worker,
  rejection of a tampered package, sync with a cross-device conflict, and key rotation.

## Installation and device acceptance (A120)

**Automated (CI job `pwa`, `node tests/pwa/installability.js`):** in Chromium desktop and an Android phone
emulation (Pixel 7 profile) the test checks the manifest (standalone, start URL/scope, 192/512/maskable
icons), Chrome's installability report, that the service worker controls the page and caches the whole
shell, the offline start from the cache and the removal of outdated caches. Result 13.11.2026: all 16
checks passed.

**Manual acceptance matrix.** Automation cannot press "Install", grant the camera or test real
hardware. Before a release, one person runs these steps on each device and records the result in the
release notes (date, device, OS/browser version, tester):

1. Open the app from the admin button (HTTPS), pair the device.
2. Install: Chrome/Edge/Samsung Internet „App installieren“; iOS/iPadOS Safari „Teilen → Zum Home-Bildschirm“.
3. Start from the home screen icon: opens without browser UI, correct icon and name.
4. Allow the camera, scan a valid QR code, then the same code again (duplicate warning), then a help code online.
5. Airplane mode: close and reopen the app from the icon, scan a valid code (admitted offline), then go online and check that the sync uploads it.
6. Lock the screen during a scan session and resume: the camera restarts, no data lost.
7. Update: after a plugin update the app shows the new version after the next start (new cache).

| Platform | Browser | Installation | Automated | Manual status |
|---|---|---|---|---|
| Android 13+ | Chrome | install prompt / menu | yes (emulation) | pending |
| Android 13+ | Samsung Internet | menu „Seite hinzufügen → Startbildschirm“ | – | pending |
| Android 13+ | Firefox | menu „Installieren“ | – | pending |
| iOS/iPadOS 17+ | Safari | „Zum Home-Bildschirm“ (camera via jsQR fallback, no BarcodeDetector) | – | pending |
| iOS/iPadOS 17+ | Chrome/Firefox (WebKit) | only via Safari | – | not supported for installation |
| Windows/macOS/Linux | Chrome, Edge | address bar install icon | yes | pending |
| macOS 14+ | Safari | „Ablage → Zum Dock hinzufügen“ | – | pending |
| any | Firefox desktop | no PWA installation; works as a website | – | runs in the browser tab only |

Known platform limits: iOS evicts service worker storage after weeks without use, so an offline device
must be opened online before an event to refresh the package. Background sync is not available on iOS,
so scans upload when the app is open and online.
