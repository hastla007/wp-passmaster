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
deletes stored packages that the server no longer lists or refuses with 403. On `401` (or an error code
`…device_revoked`/`…device_unauthenticated`) it wipes the device, see *Revoked token* below.

## REST (device bearer token, namespace `wp-pass-captain/v1`)

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
  "event": {"id": 1, "name": "…"}, "occurrence": {"id": 2, "starts_at": "…", "ends_at": null, "timezone": "Europe/Vienna", "status": "scheduled"},
  "issued_at": "…", "expires_at": "…", "since": null, "cursor": "a1b2c3d4e5f60718.7",
  "rules": {"max_offline_hours": 12, "duplicate_policy": "review", "attendee_names": false},
  "device_area_id": null, "hash": "sha256",
  "ticket_types": [{"id": 5, "name": "Standard", "areas": [], "policy": "single", "multi": false}],
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
| `app.css` | Styles. |
| `sw.js` | Service worker that caches the app shell. API calls are never cached. Only navigations to the app itself (scope root or `index.html`) are served from and stored as `index.html`, and only `text/html` responses are stored; other navigations inside the scope are network-only. |
| `manifest.json`, icons | Makes the app installable. Locale-neutral on purpose (product name only, no `lang`/description): a static file cannot follow the device language. |
| `../vendor/tweetnacl/` | tweetnacl-js 1.0.3 (Unlicense), used for signature verification. |
| `../vendor/jsqr/` | Camera fallback when `BarcodeDetector` is unavailable. |

- **Start:** open the app from the admin button. The link passes `?api=<rest_url>`, which is stored.
  It is only used while the device is **not paired**; a paired device keeps its stored API base and
  ignores the parameter (the parameter is removed from the address bar either way). Accepted API
  bases (parameter, stored value and the `api` of the pairing QR): **same origin** and exactly the
  plugin's app namespace, either a REST path ending in `/wp-pass-captain/v1/app/` or
  `…?rest_route=/wp-pass-captain/v1/app/` as the only query parameter; anything else falls back to the
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
  - Online, the app calls `POST /app/scan/check-in` with an idempotency key (5 s timeout).
  - On a network error or 5xx it decides offline. It normalizes the code like the server, computes
    its SHA-256 with WebCrypto and looks the hash up in the package. Signed QR codes (PC1) are
    verified locally first and matched by code version (see above).
  - A ticket is refused offline when it is unknown, revoked, already used (single entry: package
    status or the local log), in the wrong area (the type's area restriction against the device's or
    the selected area), or when the package is missing or expired.
  - Multi-entry types are admitted offline. The server checks their limits at sync.
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
  manipulation of the device clock, and keeps the last known server time (`server_time` of the keys
  endpoint, `issued_at` of verified packages; IndexedDB `kv.server_time`) as a lower bound for its
  clock: setting the device clock back does not extend a package. Package expiry is still enforced on
  the device, so a rooted device can bypass it. The server then flags old scans (`offline_expired`).
- **Camera:** stopped (all tracks ended) when the app is hidden or left (`visibilitychange`,
  `pagehide`) and after a 401 wipe.
- **Diagnostics:** `window.wpcapOffline` exposes helper functions and a view of the state without the
  device token.

Ship changed shell files by bumping `CACHE` in `sw.js`. Assets are also refreshed in the background
(stale-while-revalidate).

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
- `tests/integration/modules/offline_scanner.php` covers opt-in, assignment, signatures (including
  `sodium_crypto_sign_verify_detached`), deltas with revocations, expiry, rotation and grace, and the
  sync rules.
- A Playwright check against a real WordPress install drove the end-to-end flow: pairing, admin
  opt-in and assignment, online and offline scans, an offline reload through the service worker,
  rejection of a tampered package, sync with a cross-device conflict, and key rotation.
