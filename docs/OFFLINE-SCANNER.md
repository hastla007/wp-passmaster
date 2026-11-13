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

## Event rules (`wpcap_offline_events`)

| Setting | Default | Meaning |
|---|---|---|
| `enabled` | off | Only opted-in events deliver packages (`403 wpcap_offline_disabled` otherwise). |
| `max_offline_hours` | 12 (1–72) | Package lifetime (`expires_at = issued_at + hours`). Uploaded offline scans older than this are admitted, but recorded as an open conflict `offline_expired`. |
| `duplicate_policy` | `review` | Rule for a ticket admitted offline on two devices. `review`: the later upload becomes an open conflict. `first_sync_wins`: the first upload counts; the later one is still recorded but resolved automatically as `duplicate_scan` (`resolved_by = 0`, audit `admission_conflict_auto_resolved`). |
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
deletes stored packages that the server no longer lists or refuses with 403.

## REST (device bearer token, namespace `pass-captain/v1`)

| Route | Description |
|---|---|
| `GET /app/offline/keys` | Trusted public keys: `{keys:[{key_id, alg:"Ed25519", public_key (base64), status: active\|retired, created_at, valid_until}], server_time}` |
| `GET /app/offline/occurrences` | Occurrences this device may hold packages for, including their rules. |
| `GET /app/offline/package?occurrence_id=&since=` | Signed package: a full package, or a delta when `since` is a valid cursor. |

Existing responses are also extended:

- `POST /app/pair` adds `offline: {keys, package_format}`, which delivers the public keys at pairing.
- `GET /app/me` sets `features.offline_packages = true`.
- `POST /app/scan/sync` result rows gain `offline_rules`, `auto_resolved`, `offline_expired` and `conflict_status`.

### Package response

```json
{ "alg": "Ed25519", "key_id": "…16 hex…", "payload": "<JSON string>", "signature": "<base64>",
  "kind": "full|delta", "cursor": "<epoch>.<version>", "count": 3, "expires_at": "…" }
```

The signature covers the exact UTF-8 bytes of `payload`. Clients must verify the signature first,
then parse `payload`, and use only the signed fields. The fields outside `payload` are convenience copies.

Signed payload (`v` = package format version 1):

```json
{ "format": "wpcap-offline-package", "v": 1, "kind": "full", "site": "https://…/",
  "device_id": "<public device id>", "key_id": "…",
  "event": {"id": 1, "name": "…"}, "occurrence": {"id": 2, "starts_at": "…", "ends_at": null, "timezone": "Europe/Vienna", "status": "scheduled", "doors_open_at": null},
  "issued_at": "…", "expires_at": "…", "since": null, "cursor": "a1b2c3d4e5f60718.7",
  "rules": {"max_offline_hours": 12, "duplicate_policy": "review", "attendee_names": false},
  "device_area_id": null, "hash": "sha256",
  "ticket_types": [{"id": 5, "name": "Standard", "areas": [], "policy": "single", "multi": false, "id_check": false, "min_age": 0, "hint": ""}],
  "areas": [{"id": 3, "name": "Eingang Nord"}],
  "tickets": [{"h": "<sha256(code) hex>", "s": "valid|checked_in|revoked", "t": 5, "n": "Name (optional)"}] }
```

What the package contains:

- **Full packages** contain the `valid` and `checked_in` tickets.
- **Deltas** (`since` = the previous cursor) contain every changed hash, including `revoked`. A hash
  becomes `revoked` when its ticket is revoked, when its code is reissued (the old hash goes away), or
  when the occurrence or event is cancelled.
- An unknown epoch or a future cursor gives a full package.
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
Audit actions: `offline_key_rotated`, `offline_rules_saved`, `offline_device_assigned` /
`offline_device_unassigned`.

## PWA (`assets/offline-scanner/`)

| File | Contents |
|---|---|
| `index.html` | App shell (German source texts, marked with `data-i18n`). Strict CSP with `connect-src 'self'`. |
| `i18n.js` | Static translation catalogs (German source, English); works offline. |
| `app.js` | App logic; all visible texts go through `t()`. |
| `app.css` | Styles. |
| `sw.js` | Service worker that caches the app shell. API calls are never cached. |
| `manifest.json`, icons | Makes the app installable. |
| `../vendor/tweetnacl/` | tweetnacl-js 1.0.3 (Unlicense), used for signature verification. |
| `../vendor/jsqr/` | Camera fallback when `BarcodeDetector` is unavailable. |

- **Start:** open the app from the admin button. The link passes `?api=<rest_url>`, which is stored.
- **Language:** the link also passes `?lang=` (WordPress user locale). The app remembers it on the
  device for offline starts and otherwise falls back to the browser language, then German. Dates use
  the matching locale. Add a language by adding a catalog to `i18n.js` (keys = German source text).
  Only **same-origin** API bases are accepted. HTTPS (or localhost) is required for the service
  worker and WebCrypto.
- **Pairing:** enter the pairing code, or paste the JSON payload of the pairing QR. The device token
  and public keys are stored in IndexedDB.
- **Packages:** the app stores one full package plus chained deltas per occurrence (at most 30, then
  it fetches a new full package). Every document is re-verified on each start:
  - the signature with the known key (retired keys only until `valid_until`),
  - `format`/`v`, `key_id` and `device_id` (packages are bound to the device),
  - the chain (`delta.since === previous cursor`).
- **Scanning:**
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
  - A ticket is refused offline when it is unknown, revoked, already used (single entry: package
    status or the local log), in the wrong area (the type's area restriction against the device's or
    the selected area), or when the package is missing or expired.
  - Multi-entry types are admitted offline. The server checks their limits at sync.
  - **Help code** (8 characters, e.g. `K7QM-4XWA`, printed below the QR code): typed into the manual
    field when the QR code cannot be read. It is checked **online only** (the package holds no help
    codes, see docs/SECURITY.md); offline the app asks for the QR code or the long code instead.
  - Result: large coloured box, distinct tones (WebAudio, “Sound on/off” per device) and vibration
    patterns; online results show “first admission” or “already admitted at …” from the server.
- **Log and sync:** every scan is logged locally with its idempotency key. Offline admissions are
  uploaded through `POST /app/scan/sync` (batches of 200) when the device is online, on reconnect and
  every 30 s. The ticket code is kept only until the upload succeeded. Server conflicts, including
  auto-resolved ones, are listed in the app.
- **Clock:** the app takes an offset from the server time of the keys endpoint, to limit
  manipulation of the device clock. Package expiry is still enforced on the device, so a rooted
  device can bypass it. The server then flags old scans (`offline_expired`).

Ship changed shell files by bumping `CACHE` in `sw.js`. Assets are also refreshed in the background
(stale-while-revalidate).

## Security notes

- A package lets a device recognize valid ticket **hashes**, not forge tickets: the codes cannot be
  derived from the hashes (256-bit random codes).
- Revoking a device makes its token invalid (`401`). A package it already holds stays usable offline
  until it expires. Keep `max_offline_hours` short.
- Every offline admission is re-checked at sync. Double admissions are never silently dropped: they
  are always recorded as admission conflicts.

## Tests

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
