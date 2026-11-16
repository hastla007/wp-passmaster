/**
 * Pass Captain offline scanner (PWA).
 *
 * - Pairs via the existing pairing-code flow (POST /app/pair) and stores the device token locally.
 * - Loads signed verification packages (GET /app/offline/package) for assigned occurrences, verifies
 *   the Ed25519 signature with tweetnacl against the public keys from the server (pairing response /
 *   GET /app/offline/keys) and keeps full package + deltas in IndexedDB (re-verified on every start).
 * - Scans: online first (POST /app/scan/check-in); without network the scan is decided locally
 *   against the package (sha256 of the code via WebCrypto) and written to a local log with an
 *   idempotency key. Pending scans are uploaded via POST /app/scan/sync; conflicts are shown.
 * - Signed QR codes (PC1.<base64url JSON>.<base64url Ed25519>, see docs/OFFLINE-SCANNER.md) are
 *   pre-validated offline: signature against the QR keys (qr_keys), validity window, occurrence, and the
 *   code version (first 16 hex of the code hash) is looked up in the package. Online the server decides.
 *
 * i18n: t() looks UI texts up by their German source text in a table from GET /app/offline/i18n
 * (locale + translations via the plugin's text domain). The app asks for the device languages
 * (navigator.languages, ?locale=); the server answers with the first one it has a translation for,
 * otherwise the site language. The table is kept in IndexedDB so the app is translated offline too;
 * without a table the German source texts are shown.
 *
 * Scan feedback: a scan first shows the neutral "Prüfe …" state, the result stays ~4 s and then
 * returns to "Bereit"; the same code is ignored for 10 s (a QR held in front of the camera). A code
 * this device admitted a moment ago shows yellow "Eben hier eingelassen (vor X s)" instead of red.
 * Tones (WebAudio, switchable) and vibration accompany every result.
 *
 * Packages: format 2 (compact rows of 32-hex hash prefixes, see PassCaptain\OfflineScanner\Package)
 * is requested and format 1 still read; the ticket index is keyed by the first 32 hex digits of the
 * hash. After MAX_DELTAS deltas the app asks for one compact delta since the full package instead of
 * a new full package. Package downloads may take 120 s and show their progress.
 *
 * Multi-entry tickets offline: local entry counters per hash (daily/multi_day once per service day,
 * multi_entry up to max_entries, reentry while not inside) from the device's own scan log.
 *
 * Security: the API must be same-origin (also enforced by the page CSP). All server/package text is
 * rendered with textContent. Ticket codes are kept only until the scan is synced.
 */
(function () {
	'use strict';

	var APP_VERSION = '1.0.0';
	var DB_NAME = 'wpcap-offline-scanner';
	var MAX_DELTAS = 30;
	var REFRESH_MS = 60000;
	var SYNC_BATCH = 200;
	var LOG_MAX_AGE_MS = 24 * 3600e3; // synced log entries are kept for a day ...
	var LOG_MAX_ENTRIES = 500; // ... and at most this many (pending scans are always kept)
	var DAY_S = 86400;
	var RESULT_HOLD_MS = 4000; // a result stays visible this long, then the app is "Bereit" again
	var SAME_CODE_LOCK_MS = 10000; // the same code (held in front of the camera) is ignored this long
	var RECENT_MS = 300000; // "Eben hier eingelassen": local admissions of the last 5 minutes
	var PACKAGE_TIMEOUT_MS = 120000; // full packages of large events need time on weak networks
	var PACKAGE_FORMAT = 2; // compact package format (format 1 is still read)
	var HASH_KEY = 32; // hex digits of the ticket hash used as index key (= hash prefix of format 2)
	var API_SUFFIX = '/wp-pass-captain/v1/app/';
	var nacl = window.nacl;

	var state = {
		api: '', token: '', deviceId: '', keys: {}, qrKeys: {}, clockOffset: 0,
		serverTime: 0, // last known server time (ms): lower bound for now(), the device clock may be set back
		hw: {}, // occurrence_id -> { cursor, issued_at } of the newest accepted package (rollback protection)
		occurrences: [], selected: 0, areaId: 0,
		packages: {}, // occurrence_id -> { record, index: Map, meta, error }
		admitted: {}, // occurrence_id -> Set of hash keys admitted on this device
		local: {}, // occurrence_id -> Map hash key -> { ins: [ms of admissions], inside: bool } (entry counters)
		sound: true, // result tones (kv "sound")
		busy: false, lastRefresh: 0, pendingCount: 0,
		i18n: null, // { v, locale, lang, strings } from GET /app/offline/i18n
		forceFull: {} // occurrence_id -> true after a broken delta chain
	};

	function $(id) { return document.getElementById(id); }
	/** Current time: device clock corrected by the server offset, never earlier than the last known server time. */
	function now() { return Math.max(Date.now() + state.clockOffset, state.serverTime || 0); }
	/** Raises the server time floor (server_time of a response, issued_at of a verified package). */
	function noteServerTime(ms, replace) {
		if (!isFinite(ms) || ms <= 0) { return; }
		var next = replace ? ms : Math.max(state.serverTime || 0, ms);
		if (next === state.serverTime) { return; }
		state.serverTime = next;
		kvSet('server_time', next).catch(function () {});
	}

	/* ---------------- IndexedDB ---------------- */

	var dbPromise = null;
	function db() {
		if (dbPromise) { return dbPromise; }
		dbPromise = new Promise(function (resolve, reject) {
			var req = indexedDB.open(DB_NAME, 1);
			req.onupgradeneeded = function () {
				var d = req.result;
				d.createObjectStore('kv');
				d.createObjectStore('packages', { keyPath: 'occurrence_id' });
				var log = d.createObjectStore('log', { keyPath: 'key' });
				log.createIndex('synced', 'synced');
			};
			req.onsuccess = function () { resolve(req.result); };
			req.onerror = function () { reject(req.error); };
		});
		return dbPromise;
	}
	function idb(store, mode, fn) {
		return db().then(function (d) {
			return new Promise(function (resolve, reject) {
				var tx = d.transaction(store, mode);
				var out = fn(tx.objectStore(store));
				tx.oncomplete = function () { resolve(out && 'result' in out ? out.result : out); };
				tx.onerror = function () { reject(tx.error); };
				tx.onabort = function () { reject(tx.error); };
			});
		});
	}
	function kvGet(key) { return idb('kv', 'readonly', function (s) { return s.get(key); }); }
	function kvSet(key, value) { return idb('kv', 'readwrite', function (s) { s.put(value, key); }); }
	function kvDel(keys) { return idb('kv', 'readwrite', function (s) { keys.forEach(function (k) { s.delete(k); }); }); }
	function allOf(store) { return idb(store, 'readonly', function (s) { return s.getAll(); }); }
	function put(store, value) { return idb(store, 'readwrite', function (s) { s.put(value); }); }

	/* ---------------- helpers ---------------- */

	function b64ToBytes(b64) {
		var bin = atob(b64);
		var out = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); }
		return out;
	}
	function hex(buffer) {
		return Array.prototype.map.call(new Uint8Array(buffer), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}
	function sha256Hex(text) {
		return crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(hex);
	}
	function b64uToBytes(text) {
		var s = String(text).replace(/-/g, '+').replace(/_/g, '/');
		if (s.length % 4 === 1) { throw new Error('base64url'); }
		while (s.length % 4) { s += '='; }
		return b64ToBytes(s);
	}
	var SIGNED_QR = /PC1\.[A-Za-z0-9_-]{16,700}\.[A-Za-z0-9_-]{86}/;
	/** Signed QR payload contained in a scanned value, or ''. */
	function signedCode(value) {
		var m = String(value || '').match(SIGNED_QR);
		return m ? m[0] : '';
	}
	/** Same rule as PassCaptain\Checkin::normalize_code(): first 64-hex run, lowercase. */
	function normalizeCode(value) {
		var m = String(value || '').match(/[a-f0-9]{64}/i);
		return m ? m[0].toLowerCase() : '';
	}
	function uuid() {
		if (crypto.randomUUID) { return crypto.randomUUID(); }
		var b = crypto.getRandomValues(new Uint8Array(16));
		return hex(b.buffer);
	}
	/* ---------------- i18n ---------------- */

	var I18N_VERSION = 1;
	var dateFormat = null;

	/** Translation of a German source text; printf-style %s/%d and %1$s placeholders. */
	function t(key) {
		var table = state.i18n && state.i18n.strings;
		var text = table && Object.prototype.hasOwnProperty.call(table, key) && typeof table[key] === 'string' ? table[key] : key;
		var args = Array.prototype.slice.call(arguments, 1);
		if (!args.length) { return text; }
		var next = 0;
		return text.replace(/%(?:(\d+)\$)?([sd%])/g, function (m, pos, type) {
			if ('%' === type) { return '%'; }
			var v = args[pos ? Number(pos) - 1 : next++];
			if (v === undefined || v === null) { return ''; }
			return 'd' === type ? String(Math.trunc(Number(v)) || 0) : String(v);
		});
	}
	function lang() { return (state.i18n && state.i18n.lang) || 'de-DE'; }
	/** Accepts only well-formed tables of the known format version. */
	function validI18n(table) {
		return !!(table && I18N_VERSION === table.v && typeof table.lang === 'string' && table.strings && typeof table.strings === 'object');
	}
	function fmtTime(iso) {
		var d = new Date(iso);
		if (isNaN(d)) { return '—'; }
		if (!dateFormat) {
			try { dateFormat = new Intl.DateTimeFormat(lang(), { dateStyle: 'short', timeStyle: 'short' }); } catch (e) { dateFormat = new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'short' }); }
		}
		return dateFormat.format(d);
	}
	function el(tag, text, cls) {
		var e = document.createElement(tag);
		if (text !== undefined && text !== null) { e.textContent = String(text); }
		if (cls) { e.className = cls; }
		return e;
	}

	/** German source texts of the result codes (keys of the i18n table); translated on lookup. */
	var RESULT_TEXT = {
		checked_in: 'Einlass OK', already_checked_in: 'Bereits verwendet', revoked: 'Widerrufen', unknown: 'Unbekanntes Ticket',
		wrong_event: 'Falsches Event', wrong_occurrence: 'Falscher Termin', wrong_area: 'Falscher Einlassbereich',
		cancelled_event: 'Termin abgesagt', out_of_window: 'Außerhalb des Einlasszeitraums', not_valid_today: 'Heute nicht gültig',
		daily_limit_reached: 'Tageskontingent erreicht', entry_limit_reached: 'Einlasslimit erreicht', outside_entry_window: 'Außerhalb des Einlassfensters',
		already_inside: 'Bereits drinnen', forbidden: 'Kein Einlasszugriff', package_expired: 'Prüfpaket abgelaufen', no_package: 'Kein gültiges Prüfpaket',
		invalid_code: 'Kein Ticketcode erkannt', invalid_signature: 'QR-Signatur ungültig', retry: 'Bitte erneut scannen'
	};
	function resultText(code) { return Object.prototype.hasOwnProperty.call(RESULT_TEXT, code) ? t(RESULT_TEXT[code]) : code; }

	var resultTimer = 0;
	var audio = null;
	/** Result tone via WebAudio (switchable): high = admitted, double = warning, low buzz = rejected. */
	function beep(kind) {
		if (!state.sound) { return; }
		var Ctx = window.AudioContext || window.webkitAudioContext;
		if (!Ctx) { return; }
		try {
			audio = audio || new Ctx();
			if ('suspended' === audio.state && audio.resume) { audio.resume(); }
			var tones = 'ok' === kind ? [[880, 0, 0.12]] : 'warn' === kind ? [[660, 0, 0.1], [660, 0.16, 0.1]] : [[220, 0, 0.35]];
			tones.forEach(function (tone) {
				var osc = audio.createOscillator();
				var gain = audio.createGain();
				osc.type = 'bad' === kind ? 'square' : 'sine';
				osc.frequency.value = tone[0];
				gain.gain.value = 0.15;
				osc.connect(gain);
				gain.connect(audio.destination);
				var at = (audio.currentTime || 0) + tone[1];
				osc.start(at);
				osc.stop(at + tone[2]);
			});
		} catch (e) { /* no audio output */ }
	}
	function showIdle() {
		clearTimeout(resultTimer);
		$('result').className = 'result idle';
		$('result-title').textContent = t('Bereit');
		$('result-detail').textContent = '';
	}
	/**
	 * Shows a result: kind ok (green), warn (yellow), bad (red) or pending (neutral, while checking).
	 * Scan results (hold = true) go back to "Bereit" after RESULT_HOLD_MS, so the next guest never sees
	 * the previous guest's result.
	 */
	function showResult(kind, title, detail, hold) {
		clearTimeout(resultTimer);
		var box = $('result');
		box.className = 'result ' + kind;
		$('result-title').textContent = title;
		$('result-detail').textContent = detail || '';
		if ('pending' === kind) { return; }
		beep(kind);
		if (navigator.vibrate) { try { navigator.vibrate('ok' === kind ? 80 : [60, 60, 60]); } catch (e) { /* not allowed */ } }
		if (hold) { resultTimer = setTimeout(showIdle, RESULT_HOLD_MS); }
	}

	/* ---------------- API ---------------- */

	function deriveApi() {
		var path = location.pathname;
		var i = path.indexOf('/wp-content/');
		return location.origin + (i >= 0 ? path.slice(0, i) : '') + '/wp-json/wp-pass-captain/v1/app/';
	}
	/** Only same-origin API bases are accepted (the token must never go to another host). */
	function sameOrigin(url) {
		try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
	}
	/**
	 * A usable API base: same origin and exactly the plugin's app namespace, either as a pretty REST path
	 * (…/wp-pass-captain/v1/app/, any REST prefix) or as the only query parameter rest_route=/wp-pass-captain/v1/app/.
	 */
	function validApiBase(url) {
		if (typeof url !== 'string' || !url || !sameOrigin(url)) { return false; }
		var u;
		try { u = new URL(url, location.href); } catch (e) { return false; }
		if (u.hash || u.username || u.password) { return false; }
		if (!u.search) { return u.pathname.length > API_SUFFIX.length && u.pathname.slice(-API_SUFFIX.length) === API_SUFFIX; }
		var params = Array.from(u.searchParams.keys());
		return 1 === params.length && API_SUFFIX === u.searchParams.get('rest_route') && url.slice(-('rest_route=' + API_SUFFIX).length) === 'rest_route=' + API_SUFFIX;
	}
	function endpoint(route, query) {
		var base = state.api;
		var url;
		if (base.indexOf('rest_route=') >= 0) {
			url = base + route + (query ? '&' + query : '');
		} else {
			url = base + route + (query ? '?' + query : '');
		}
		return url;
	}

	function ApiError(status, body) { this.status = status; this.body = body || {}; this.message = (body && body.message) || ('HTTP ' + status); }

	/** Reads a response body as JSON, reporting the received bytes (streams where available). */
	function readJson(res, onProgress) {
		if (!onProgress || !res.body || typeof res.body.getReader !== 'function') { return res.json(); }
		var reader = res.body.getReader();
		var chunks = [];
		var loaded = 0;
		function pump() {
			return reader.read().then(function (step) {
				if (step.done) {
					var all = new Uint8Array(loaded);
					var at = 0;
					chunks.forEach(function (c) { all.set(c, at); at += c.length; });
					return JSON.parse(new TextDecoder().decode(all));
				}
				chunks.push(step.value);
				loaded += step.value.length;
				try { onProgress(loaded); } catch (e) { /* display only */ }
				return pump();
			});
		}
		return pump();
	}

	function api(method, route, body, query, timeoutMs, onProgress) {
		var ctrl = window.AbortController ? new AbortController() : null;
		var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, timeoutMs || 15000) : null;
		var headers = { 'Accept': 'application/json' };
		if (state.token) { headers.Authorization = 'Bearer ' + state.token; }
		if (body) { headers['Content-Type'] = 'application/json'; }
		return fetch(endpoint(route, query), { method: method, headers: headers, body: body ? JSON.stringify(body) : undefined, credentials: 'omit', cache: 'no-store', signal: ctrl ? ctrl.signal : undefined })
			.then(function (res) {
				return readJson(res, res.ok ? onProgress : null).catch(function () { return {}; }).then(function (data) {
					if (timer) { clearTimeout(timer); }
					if (!res.ok) { throw new ApiError(res.status, data); }
					return data;
				});
			}, function (err) {
				if (timer) { clearTimeout(timer); }
				err.network = true;
				throw err;
			});
	}

	/** True for responses that mean the device token is no longer valid (expired, revoked device). */
	function isAuthError(err) {
		var code = err && err.body && typeof err.body.code === 'string' ? err.body.code : '';
		return !!err && (401 === err.status || /device_revoked|device_unauthenticated/.test(code));
	}

	/**
	 * Removes everything the device holds for its pairing: token, device id, keys, packages (attendee
	 * names), the scan log (ticket codes of pending scans) and the rollback/time markers. Translations and
	 * the API base stay so the device can be paired again. Used when the server rejects the token.
	 */
	function wipeDevice() {
		state.token = '';
		state.deviceId = '';
		state.keys = {};
		state.qrKeys = {};
		state.packages = {};
		state.admitted = {};
		state.occurrences = [];
		state.selected = 0;
		state.hw = {};
		state.forceFull = {};
		state.pendingCount = 0;
		return Promise.all([
			kvDel(['token', 'device_id', 'keys', 'qr_keys', 'clock_offset', 'server_time', 'hw']),
			idb('packages', 'readwrite', function (s) { s.clear(); }),
			idb('log', 'readwrite', function (s) { s.clear(); })
		]).catch(function () {});
	}

	function handleAuthError(err) {
		if (isAuthError(err)) {
			var wasPaired = !!state.token;
			showResult('bad', t('Gerät nicht mehr gekoppelt'), t('Token abgelaufen oder widerrufen. Bitte neu koppeln.'));
			if (wasPaired) {
				stopCamera();
				state.wiping = wipeDevice().then(function () { state.wiping = null; showView(); });
				showView();
			}
			return true;
		}
		return false;
	}

	/* ---------------- keys and signatures ---------------- */

	function setKeys(list) {
		var keys = {};
		(list || []).forEach(function (k) {
			if (k && 'Ed25519' === k.alg && k.key_id && k.public_key) {
				keys[k.key_id] = { public_key: k.public_key, status: k.status, valid_until: k.valid_until };
			}
		});
		state.keys = keys;
		return kvSet('keys', keys);
	}

	function setQrKeys(list) {
		var keys = {};
		(list || []).forEach(function (k) {
			if (k && 'Ed25519' === k.alg && k.key_id && k.public_key) {
				keys[k.key_id] = { public_key: k.public_key, status: k.status, valid_until: k.valid_until };
			}
		});
		state.qrKeys = keys;
		return kvSet('qr_keys', keys);
	}

	/**
	 * Validity window [nbf, exp] (Unix seconds) of signed QR claims. With the current occurrence of the
	 * package (same id as the claims) it is widened to [min(signed start, start - 365 days),
	 * max(signed end, (end or start) + 1 day)], mirroring PassCaptain\Security\SignedQr::in_window(): a QR
	 * code sent before the occurrence was moved stays valid for the new date (audit QA-2).
	 */
	function qrWindow(claims, occurrence) {
		var nbf = claims.v[0], exp = claims.v[1];
		if (occurrence && occurrence.id === claims.o) {
			var start = Date.parse(occurrence.starts_at);
			var end = occurrence.ends_at ? Date.parse(occurrence.ends_at) : start;
			if (isFinite(start)) { nbf = Math.min(nbf, Math.floor(start / 1000) - 365 * DAY_S); }
			if (isFinite(end)) { exp = Math.max(exp, Math.floor(end / 1000) + DAY_S); }
		}
		return [nbf, exp];
	}

	/**
	 * Offline pre-validation of a signed QR code (mirrors PassCaptain\Security\SignedQr::verify()):
	 * Ed25519 signature over the ASCII bytes "PC1.<claims>" with a trusted QR key (retired keys only
	 * until valid_until), claim types and the validity window at atMs. The ticket state is decided by the
	 * package (and finally by the server on sync).
	 *
	 * @param {Object} [occurrence] Current occurrence of the verified package ({id, starts_at, ends_at}):
	 *   widens the window to the current dates like the server does (see qrWindow()).
	 * @return {{ok: boolean, result: string, claims: (Object|null)}}
	 */
	function verifySignedQr(code, atMs, occurrence) {
		var bad = { ok: false, result: 'invalid_signature', claims: null };
		var parts = String(code || '').split('.');
		if (3 !== parts.length || 'PC1' !== parts[0] || !SIGNED_QR.test(code)) { return bad; }
		var claims;
		try {
			claims = JSON.parse(new TextDecoder().decode(b64uToBytes(parts[1])));
		} catch (e) { return bad; }
		var key = claims && typeof claims.k === 'string' && Object.prototype.hasOwnProperty.call(state.qrKeys, claims.k) ? state.qrKeys[claims.k] : null;
		if (!key || (key.valid_until && Date.parse(key.valid_until) < now())) { return bad; }
		var ok = false;
		try {
			ok = nacl.sign.detached.verify(new TextEncoder().encode(parts[0] + '.' + parts[1]), b64uToBytes(parts[2]), b64ToBytes(key.public_key));
		} catch (e) { ok = false; }
		if (!ok) { return bad; }
		var valid = typeof claims.t === 'string' && Number.isInteger(claims.o) && claims.o > 0 && Array.isArray(claims.v) && 2 === claims.v.length &&
			Number.isInteger(claims.v[0]) && Number.isInteger(claims.v[1]) && typeof claims.c === 'string' && /^[a-f0-9]{16}$/.test(claims.c);
		if (!valid) { return bad; }
		var at = Math.floor((atMs === undefined || atMs === null ? now() : atMs) / 1000);
		var win = qrWindow(claims, occurrence);
		if (at < win[0] || at > win[1]) { return { ok: false, result: 'out_of_window', claims: claims }; }
		return { ok: true, result: 'valid', claims: claims };
	}

	/**
	 * Verifies a signed package document and returns its payload, or throws.
	 * Checks: known key (retired keys only until valid_until), Ed25519 signature over the exact
	 * payload bytes, format/version, key id and device binding.
	 */
	function verifyDoc(doc) {
		var key = doc && state.keys[doc.key_id];
		if (!key) { throw new Error(t('Unbekannter Signaturschlüssel')); }
		if (key.valid_until && Date.parse(key.valid_until) < now()) { throw new Error(t('Signaturschlüssel abgelaufen')); }
		var ok = false;
		try {
			ok = nacl.sign.detached.verify(new TextEncoder().encode(doc.payload), b64ToBytes(doc.signature), b64ToBytes(key.public_key));
		} catch (e) { ok = false; }
		if (!ok) { throw new Error(t('Signatur ungültig')); }
		var payload = JSON.parse(doc.payload);
		if ('wpcap-offline-package' !== payload.format || (1 !== payload.v && 2 !== payload.v)) { throw new Error(t('Unbekanntes Paketformat')); }
		if (2 === payload.v) { payload.tickets = compactTickets(payload); }
		if (!Array.isArray(payload.tickets)) { throw new Error(t('Unbekanntes Paketformat')); }
		if (payload.key_id !== doc.key_id) { throw new Error(t('Schlüssel-ID passt nicht')); }
		if (payload.device_id !== state.deviceId) { throw new Error(t('Paket gehört zu einem anderen Gerät')); }
		return payload;
	}

	var STATUS_OF_DIGIT = ['revoked', 'valid', 'checked_in'];
	/**
	 * Tickets of a format-2 payload: rows [type, status digit, concatenated 32-hex hash prefixes] and the
	 * optional names map, as the {h, s, t, n} entries of format 1 (h = hash prefix).
	 */
	function compactTickets(payload) {
		var size = payload.hash_prefix;
		if (HASH_KEY !== size || !Array.isArray(payload.rows)) { throw new Error(t('Unbekanntes Paketformat')); }
		var names = payload.names && typeof payload.names === 'object' ? payload.names : {};
		var out = [];
		payload.rows.forEach(function (row) {
			var status = Array.isArray(row) ? STATUS_OF_DIGIT[row[1]] : undefined;
			if (!status || typeof row[2] !== 'string' || row[2].length % size || !/^[a-f0-9]*$/.test(row[2])) { throw new Error(t('Unbekanntes Paketformat')); }
			for (var i = 0; i < row[2].length; i += size) {
				var h = row[2].slice(i, i + size);
				var entry = { h: h, s: status };
				if ('revoked' !== status) { entry.t = row[0]; }
				if ('revoked' !== status && Object.prototype.hasOwnProperty.call(names, h) && typeof names[h] === 'string') { entry.n = names[h]; }
				out.push(entry);
			}
		});
		return out;
	}
	/** Index key of a ticket hash: its first HASH_KEY hex digits (full sha256 or format-2 prefix). */
	function hkey(hash) { return String(hash || '').slice(0, HASH_KEY); }
	/** Map of tickets keyed by hkey(); get/set/has accept full hashes and prefixes alike. */
	function ticketIndex() {
		var index = new Map();
		['get', 'set', 'has', 'delete'].forEach(function (m) {
			var orig = Map.prototype[m];
			index[m] = function (hash) { var args = Array.prototype.slice.call(arguments); args[0] = hkey(hash); return orig.apply(index, args); };
		});
		return index;
	}

	/** Rebuilds the ticket index from a stored record (full + chained deltas), verifying every document. */
	function buildIndex(record) {
		var full = verifyDoc(record.full);
		if ('full' !== full.kind || full.occurrence.id !== record.occurrence_id) { throw new Error(t('Paket passt nicht zum Termin')); }
		var index = ticketIndex();
		var prefix = new Map(); // code version (first 16 hex of the hash) -> hash, for signed QR codes
		full.tickets.forEach(function (t) { index.set(t.h, t); prefix.set(t.h.slice(0, 16), t.h); });
		var meta = full;
		var cursor = full.cursor;
		(record.deltas || []).forEach(function (doc) {
			var d = verifyDoc(doc);
			if ('delta' !== d.kind || d.since !== cursor || d.occurrence.id !== record.occurrence_id) { throw new Error(t('Delta-Kette unterbrochen')); }
			d.tickets.forEach(function (t) { index.set(t.h, t); prefix.set(t.h.slice(0, 16), t.h); });
			cursor = d.cursor;
			meta = d;
		});
		return { index: index, prefix: prefix, meta: meta, cursor: cursor, fullCursor: full.cursor };
	}

	/** Cursor "epoch.version" → {epoch, version}. */
	function parseCursor(cursor) {
		var m = /^([a-f0-9]+)\.(\d+)$/.exec(String(cursor || ''));
		return m ? { epoch: m[1], version: Number(m[2]) } : null;
	}

	/**
	 * True when a verified package is older than the newest one this device accepted for the occurrence
	 * (older issued_at, or a lower cursor version of the same epoch): a replayed older package could
	 * still list tickets as valid that were revoked or used since.
	 */
	function isRollback(occurrenceId, meta, cursor) {
		var hw = state.hw[occurrenceId];
		if (!hw) { return false; }
		if (Date.parse(meta.issued_at) < Date.parse(hw.issued_at)) { return true; }
		var a = parseCursor(cursor), b = parseCursor(hw.cursor);
		return !!(a && b && a.epoch === b.epoch && a.version < b.version);
	}

	/** Records the newest accepted package of an occurrence (cursor, issued_at) and raises the time floor. */
	function noteAccepted(occurrenceId, meta, cursor) {
		noteServerTime(Date.parse(meta.issued_at));
		var hw = state.hw[occurrenceId];
		if (hw && hw.cursor === cursor && hw.issued_at === meta.issued_at) { return; }
		state.hw[occurrenceId] = { cursor: cursor, issued_at: meta.issued_at };
		kvSet('hw', state.hw).catch(function () {});
	}

	function loadPackage(record) {
		var entry = { record: record, index: null, prefix: null, meta: null, fullCursor: '', error: '' };
		try {
			var built = buildIndex(record);
			if (isRollback(record.occurrence_id, built.meta, built.cursor)) { throw new Error(t('Kein gültiges Prüfpaket')); }
			entry.index = built.index;
			entry.prefix = built.prefix;
			entry.meta = built.meta;
			entry.fullCursor = built.fullCursor;
			noteAccepted(record.occurrence_id, built.meta, built.cursor);
		} catch (e) {
			entry.error = e.message;
		}
		state.packages[record.occurrence_id] = entry;
		return entry;
	}

	/** Expiry (ms) of a stored package record: from the verified meta, else read from its newest document. */
	function recordExpiry(entry) {
		if (entry.meta) { return Date.parse(entry.meta.expires_at); }
		var docs = [entry.record.full].concat(entry.record.deltas || []);
		for (var i = docs.length - 1; i >= 0; i--) {
			try { var exp = Date.parse(JSON.parse(docs[i].payload).expires_at); if (isFinite(exp)) { return exp; } } catch (e) { /* unreadable document */ }
		}
		return 0;
	}

	/**
	 * Deletes expired packages (they carry attendee names and are useless for decisions) from memory and
	 * IndexedDB. Runs at start and periodically; a fresh package is loaded on the next online refresh.
	 */
	function pruneExpired() {
		var t0 = now();
		var gone = Object.keys(state.packages).map(Number).filter(function (id) { return recordExpiry(state.packages[id]) <= t0; });
		if (!gone.length) { return Promise.resolve(gone); }
		gone.forEach(function (id) { delete state.packages[id]; });
		return idb('packages', 'readwrite', function (s) { gone.forEach(function (id) { s.delete(id); }); }).then(function () { return gone; }, function () { return gone; });
	}

	/**
	 * Shortens the local scan log: synced entries older than LOG_MAX_AGE_MS go, and of the rest only the
	 * newest LOG_MAX_ENTRIES synced entries stay. Pending (not yet uploaded) scans are never removed.
	 */
	function pruneLog() {
		var limit = new Date(now() - LOG_MAX_AGE_MS).toISOString();
		return idb('log', 'readwrite', function (s) {
			var req = s.getAll();
			req.onsuccess = function () {
				var synced = (req.result || []).filter(function (e) { return e.synced; });
				synced.sort(function (a, b) { return a.scanned_at < b.scanned_at ? 1 : -1; });
				synced.forEach(function (e, i) { if (i >= LOG_MAX_ENTRIES || e.scanned_at < limit) { s.delete(e.key); } });
			};
		}).catch(function () {});
	}

	/** Download progress of a package in the package box (only for the selected occurrence). */
	function packageProgress(occurrenceId) {
		return function (bytes) {
			if (occurrenceId !== state.selected) { return; }
			var box = $('pkg');
			box.className = 'pkg small';
			box.textContent = t('Prüfpaket wird geladen … %d kB', Math.round(bytes / 1024));
		};
	}

	/**
	 * Fetches and stores the package of an occurrence: a delta since the current cursor; after
	 * MAX_DELTAS deltas one compact delta since the full package (replaces the chain) instead of a new
	 * full package; a full package without a usable stored one.
	 */
	function refreshPackage(occurrenceId) {
		var current = state.packages[occurrenceId];
		var valid = !!(current && current.index && !state.forceFull[occurrenceId]);
		var compact = valid && current.record.deltas.length >= MAX_DELTAS && !!current.fullCursor;
		var usable = valid && (compact || current.record.deltas.length < MAX_DELTAS);
		var since = usable ? (compact ? current.fullCursor : current.meta.cursor) : '';
		var query = 'occurrence_id=' + encodeURIComponent(occurrenceId) + '&format=' + PACKAGE_FORMAT + (since ? '&since=' + encodeURIComponent(since) : '');
		return api('GET', 'offline/package', null, query, PACKAGE_TIMEOUT_MS, packageProgress(occurrenceId)).then(function (doc) {
			var payload = verifyDoc(doc);
			var record;
			if ('full' === payload.kind) {
				record = { occurrence_id: occurrenceId, full: doc, deltas: [] };
			} else if (compact) {
				if (payload.since !== current.fullCursor) { throw new Error(t('Delta passt nicht zum gespeicherten Paket')); }
				record = { occurrence_id: occurrenceId, full: current.record.full, deltas: [doc] };
			} else {
				if (!usable || payload.since !== current.meta.cursor) { throw new Error(t('Delta passt nicht zum gespeicherten Paket')); }
				var deltas = current.record.deltas.slice();
				var last = deltas[deltas.length - 1];
				// Empty deltas only renew the expiry; keep at most one trailing empty delta.
				if (!payload.tickets.length && last && payload.since === payload.cursor && JSON.parse(last.payload).tickets.length === 0) { deltas.pop(); }
				deltas.push(doc);
				record = { occurrence_id: occurrenceId, full: current.record.full, deltas: deltas };
			}
			var entry = loadPackage(record);
			if (entry.error) { throw new Error(entry.error); }
			delete state.forceFull[occurrenceId];
			return put('packages', record).then(function () { return entry; });
		}).catch(function (err) {
			if (!err.status && !err.network) {
				// Verification or chain error: keep the stored package, request a full one next time.
				state.forceFull[occurrenceId] = true;
				if (current) { state.packages[occurrenceId] = current; }
			}
			if (err.status === 403 && current) {
				// Opt-in withdrawn or device unassigned: drop the local package.
				delete state.packages[occurrenceId];
				return idb('packages', 'readwrite', function (s) { s.delete(occurrenceId); }).then(function () { throw err; });
			}
			throw err;
		});
	}

	/* ---------------- occurrences / UI state ---------------- */

	function renderOccurrences() {
		var sel = $('occurrence');
		sel.textContent = '';
		var list = state.occurrences.slice();
		Object.keys(state.packages).forEach(function (id) {
			id = Number(id);
			var p = state.packages[id];
			if (!list.some(function (o) { return o.occurrence_id === id; }) && p.meta) {
				list.push({ occurrence_id: id, event: p.meta.event.name, starts_at: p.meta.occurrence.starts_at });
			}
		});
		if (!list.length) {
			sel.appendChild(el('option', t('Keine zugewiesenen Termine'), ''));
		}
		list.forEach(function (o) {
			var opt = el('option', o.event + ' – ' + fmtTime(o.starts_at));
			opt.value = String(o.occurrence_id);
			sel.appendChild(opt);
		});
		if (!state.selected && list.length) { state.selected = list[0].occurrence_id; }
		sel.value = String(state.selected || '');
		renderPackage();
	}

	function packageStatus(entry) {
		if (!entry) { return { ok: false, code: 'no_package', text: t('Noch kein Prüfpaket geladen.') }; }
		if (entry.error) { return { ok: false, code: 'no_package', text: t('Prüfpaket ungültig: %s', entry.error) }; }
		if (Date.parse(entry.meta.expires_at) <= now()) { return { ok: false, code: 'package_expired', text: t('Prüfpaket abgelaufen – bitte online aktualisieren.') }; }
		return { ok: true };
	}

	function renderPackage() {
		var box = $('pkg');
		var entry = state.packages[state.selected];
		var status = packageStatus(entry);
		box.className = 'pkg small' + (status.ok ? '' : ' bad');
		box.textContent = '';
		if (!status.ok) { box.textContent = status.text; }
		if (entry && entry.meta) {
			var m = entry.meta;
			var valid = 0;
			entry.index.forEach(function (t) { if ('revoked' !== t.s) { valid++; } });
			var lines = [
				m.event.name + ' · ' + fmtTime(m.occurrence.starts_at),
				t('%1$d Tickets im Paket · signiert (Ed25519, Schlüssel %2$s) · Stand %3$s', valid, m.key_id, fmtTime(m.issued_at)),
				t('Gültig bis %1$s · Doppelscans: %2$s', fmtTime(m.expires_at), 'first_sync_wins' === m.rules.duplicate_policy ? t('erste Synchronisierung gewinnt') : t('zur Prüfung'))
			];
			lines.forEach(function (l) { box.appendChild(el('div', l)); });
			renderAreas(m);
		} else {
			$('area-wrap').hidden = true;
		}
	}

	function renderAreas(meta) {
		var wrap = $('area-wrap');
		var sel = $('area');
		if (meta.device_area_id || !meta.areas.length) {
			wrap.hidden = true;
			state.areaId = meta.device_area_id || 0;
			return;
		}
		wrap.hidden = false;
		sel.textContent = '';
		var none = el('option', t('Kein Bereich'));
		none.value = '0';
		sel.appendChild(none);
		meta.areas.forEach(function (a) { var o = el('option', a.name); o.value = String(a.id); sel.appendChild(o); });
		sel.value = String(state.areaId || 0);
		if (sel.value !== String(state.areaId || 0)) { state.areaId = 0; sel.value = '0'; }
	}

	/* ---------------- scanning ---------------- */

	/* ---------------- local entry counters ---------------- */

	/** Local entry state of a hash on this device: { ins: [ms of admissions], inside: bool }. */
	function localOf(occurrenceId, hash) {
		var map = state.local[occurrenceId];
		return (map && map.get(hkey(hash))) || { ins: [], inside: false };
	}
	/** Counts an admission of this device (online or offline) at atMs. */
	function noteLocal(occurrenceId, hash, atMs) {
		if (!state.local[occurrenceId]) { state.local[occurrenceId] = new Map(); }
		var key = hkey(hash);
		var rec = state.local[occurrenceId].get(key) || { ins: [], inside: false };
		rec.ins.push(isFinite(atMs) ? atMs : now());
		rec.inside = true;
		state.local[occurrenceId].set(key, rec);
	}
	/** Milliseconds since this device admitted the hash, when within RECENT_MS; otherwise -1. */
	function recentLocal(occurrenceId, hash) {
		var ins = localOf(occurrenceId, hash).ins;
		if (!ins.length) { return -1; }
		var ago = now() - Math.max.apply(null, ins);
		return ago >= 0 && ago < RECENT_MS ? ago : -1;
	}
	/** Service day (Y-m-d) of a time in the occurrence timezone, with the day cutoff hour of the package. */
	function serviceDay(ms, timezone, cutoffHour) {
		var d = new Date(ms - (Number(cutoffHour) || 0) * 3600e3);
		try {
			var parts = {};
			new Intl.DateTimeFormat('en-CA', { timeZone: timezone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(d).forEach(function (p) { parts[p.type] = p.value; });
			return parts.year + '-' + parts.month + '-' + parts.day;
		} catch (e) {
			return d.toISOString().slice(0, 10);
		}
	}
	/**
	 * Local limit of a multi-entry ticket type (mirrors PassCaptain\Admission\Engine::evaluate_in() with
	 * this device's own admissions): '' when admitted, otherwise the block code.
	 */
	function localLimit(entry, type, hash, atMs) {
		var policy = type.policy || 'multi_entry';
		var local = localOf(entry.record.occurrence_id, hash);
		var max = Number(type.max_entries) || 0;
		if ('daily' === policy || 'multi_day' === policy) {
			var tz = entry.meta.occurrence && entry.meta.occurrence.timezone;
			var cutoff = entry.meta.day_cutoff_hour;
			var day = serviceDay(atMs, tz, cutoff);
			if (Array.isArray(type.valid_days) && type.valid_days.indexOf(day) < 0) { return 'not_valid_today'; }
			return local.ins.some(function (ms) { return serviceDay(ms, tz, cutoff) === day; }) ? 'daily_limit_reached' : '';
		}
		if ('reentry' === policy) {
			if (local.inside) { return 'already_inside'; }
			return max > 0 && local.ins.length >= max ? 'entry_limit_reached' : '';
		}
		if ('multi_entry' === policy && type.max_entries !== undefined) {
			return local.ins.length >= Math.max(1, max) ? 'entry_limit_reached' : '';
		}
		return '';
	}

	/**
	 * Offline decision against the verified package (mirrors the server rules that can be checked locally).
	 * Rejections caused by an admission of this device within RECENT_MS carry `recent` (ms ago).
	 */
	function decideOffline(entry, hash, atMs) {
		var status = packageStatus(entry);
		if (!status.ok) { return { ok: false, result: status.code }; }
		var t = entry.index.get(hash);
		if (!t) { return { ok: false, result: 'unknown' }; }
		if ('revoked' === t.s) { return { ok: false, result: 'revoked' }; }
		var type = null;
		entry.meta.ticket_types.forEach(function (tt) { if (tt.id === t.t) { type = tt; } });
		var multi = !!(type && type.multi);
		var occurrenceId = entry.record.occurrence_id;
		var admitted = state.admitted[occurrenceId];
		var here = !!(admitted && (admitted.has(hkey(hash)) || admitted.has(hash)));
		var blocked = '';
		if (!multi && ('checked_in' === t.s || here)) {
			blocked = 'already_checked_in';
		} else if (multi) {
			blocked = localLimit(entry, type, hash, atMs === undefined || atMs === null ? now() : atMs);
		}
		if (blocked) {
			var d = { ok: false, result: blocked, ticket: t, type: type };
			var ago = 'not_valid_today' === blocked ? -1 : recentLocal(occurrenceId, hash);
			if (ago >= 0) { d.recent = ago; }
			return d;
		}
		var area = entry.meta.device_area_id || state.areaId || 0;
		if (type && type.areas.length && type.areas.indexOf(area) < 0) { return { ok: false, result: 'wrong_area', ticket: t, type: type }; }
		return { ok: true, result: 'checked_in', ticket: t, type: type, multi: multi };
	}

	/**
	 * Offline decision for a signed QR code: signature + window (verifySignedQr), occurrence of the
	 * package, then the ticket entry whose hash starts with the code version (a reissued ticket has a new
	 * hash, so QR codes of the old code are unknown/revoked here too). Returns decideOffline()'s shape plus
	 * the matched hash.
	 */
	function decideSigned(entry, code, atMs) {
		var status = packageStatus(entry);
		if (!status.ok) { return { ok: false, result: status.code, hash: '' }; }
		var v = verifySignedQr(code, atMs, entry.meta.occurrence);
		if (!v.ok) { return { ok: false, result: v.result, hash: '' }; }
		if (v.claims.o !== entry.record.occurrence_id) { return { ok: false, result: 'wrong_occurrence', hash: '' }; }
		var hash = entry.prefix ? entry.prefix.get(v.claims.c) : '';
		if (!hash) { return { ok: false, result: 'unknown', hash: '' }; }
		var d = decideOffline(entry, hash);
		d.hash = hash;
		return d;
	}

	function markAdmitted(occurrenceId, hash, atMs) {
		if (!state.admitted[occurrenceId]) { state.admitted[occurrenceId] = new Set(); }
		state.admitted[occurrenceId].add(hkey(hash));
		noteLocal(occurrenceId, hash, atMs);
	}

	/** Rejections that a recent admission on this device explains (shown yellow, not red). */
	var LOCAL_REPEAT = ['already_checked_in', 'daily_limit_reached', 'entry_limit_reached', 'already_inside'];
	/** Shows a rejection: yellow "Eben hier eingelassen (vor X s)" when this device admitted it just now. */
	function showRejected(occurrenceId, hash, result, details, ago) {
		if (ago === undefined) { ago = LOCAL_REPEAT.indexOf(result) >= 0 ? recentLocal(occurrenceId, hash) : -1; }
		if (ago >= 0) {
			showResult('warn', t('Eben hier eingelassen (vor %d s)', Math.round(ago / 1000)), [resultText(result)].concat(details).filter(Boolean).join(' · '), true);
		} else {
			showResult('bad', resultText(result), details.filter(Boolean).join(' · '), true);
		}
	}

	var lastScan = { code: '', at: 0 };
	function handleScan(raw) {
		var signed = signedCode(raw);
		var code = signed || normalizeCode(raw);
		if (!code) { showResult('bad', resultText('invalid_code'), '', true); return Promise.resolve(); }
		if (code === lastScan.code && Date.now() - lastScan.at < SAME_CODE_LOCK_MS) { return Promise.resolve(); }
		lastScan = { code: code, at: Date.now() };
		var occurrenceId = state.selected;
		var entry = state.packages[occurrenceId];
		if (!occurrenceId) { showResult('bad', t('Kein Termin gewählt'), '', true); return Promise.resolve(); }
		// Neutral state at once: the previous guest's result must never stay visible during the check.
		showResult('pending', t('Prüfe …'));
		var key = uuid();
		var scannedAt = new Date(now()).toISOString();
		var area = (entry && entry.meta && entry.meta.device_area_id) || state.areaId || 0;
		var signedHash = '';
		if (signed && entry && entry.prefix) {
			var pre = verifySignedQr(code, null, entry.meta && entry.meta.occurrence);
			if (pre.ok && pre.claims.o === occurrenceId) { signedHash = entry.prefix.get(pre.claims.c) || ''; }
		}
		return (signedHash ? Promise.resolve(signedHash) : sha256Hex(code)).then(function (hash) {
			var online = navigator.onLine !== false;
			var attempt = online
				? api('POST', 'scan/check-in', { code: code, idempotency_key: key, occurrence_id: occurrenceId, area_id: area }, null, 5000)
				: Promise.reject({ network: true });
			return attempt.then(function (res) {
				// An old server answering a database retry with HTTP 200: decide offline like an outage.
				if (!res.ok && 'retry' === res.result) { throw { network: true }; }
				var ok = !!res.ok;
				var details = [res.ticket, res.attendee_name, t('online')];
				if (ok) {
					markAdmitted(occurrenceId, hash, Date.parse(scannedAt));
					showResult('ok', resultText(res.result), details.filter(Boolean).join(' · '), true);
				} else {
					showRejected(occurrenceId, hash, res.result, details);
				}
				return put('log', { key: key, occurrence_id: occurrenceId, hash: hash, code: '', scanned_at: scannedAt, direction: 'in', area_id: area, mode: 'online', decision: res.result, ok: ok, synced: 1 });
			}).catch(function (err) {
				// Network errors, timeouts, server outages and database retries (HTTP 503) fall back to
				// the offline decision.
				if (!err || !(err.network || err.status >= 500)) {
					if (handleAuthError(err)) { return; }
					showResult('bad', t('Fehler'), err && err.message, true);
					return;
				}
				var d = signed ? decideSigned(entry, code) : decideOffline(entry, hash);
				if (signed && d.hash) { hash = d.hash; }
				var typeName = d.type ? d.type.name : '';
				var name = d.ticket && d.ticket.n ? d.ticket.n : '';
				if (d.ok) {
					markAdmitted(occurrenceId, hash, Date.parse(scannedAt));
					showResult('ok', t('Einlass OK (offline)'), [typeName, name, d.multi ? t('Mehrfachticket – Prüfung beim Sync') : ''].filter(Boolean).join(' · '), true);
				} else {
					showRejected(occurrenceId, hash, d.result, [typeName, name, t('offline')], d.recent === undefined ? -1 : d.recent);
				}
				// Only admitted scans are uploaded; the code is kept until the upload succeeded.
				return put('log', { key: key, occurrence_id: occurrenceId, hash: hash, code: d.ok ? code : '', scanned_at: scannedAt, direction: 'in', area_id: area, mode: 'offline', decision: d.result, ok: d.ok, synced: d.ok ? 0 : 1 });
			});
		}).then(renderLog);
	}

	/* ---------------- sync ---------------- */

	function syncNow() {
		if (!state.token) { return Promise.resolve(); }
		return idb('log', 'readonly', function (s) { return s.index('synced').getAll(0); }).then(function (pending) {
			if (!pending.length) { return; }
			var batch = pending.slice(0, SYNC_BATCH);
			var scans = batch.map(function (e) { return { code: e.code, idempotency_key: e.key, scanned_at: e.scanned_at, direction: e.direction, area_id: e.area_id }; });
			return api('POST', 'scan/sync', { scans: scans }, null, 30000).then(function (res) {
				var byKey = {};
				(res.results || []).forEach(function (r) { byKey[r.idempotency_key] = r; });
				return idb('log', 'readwrite', function (s) {
					batch.forEach(function (e) {
						var r = byKey[e.key];
						// Missing or retryable (transient server error): keep the scan for the next upload.
						if (!r || r.retryable) { return; }
						e.synced = 1;
						e.code = '';
						e.server = { ok: !!r.ok, result: r.result, conflict: !!r.conflict, conflict_id: r.conflict_id || 0, double_admission: !!r.double_admission, auto_resolved: !!r.auto_resolved, offline_expired: !!r.offline_expired, message: r.message || '' };
						s.put(e);
					});
				}).then(function () { if (pending.length > SYNC_BATCH) { return syncNow(); } });
			}, function (err) { if (!handleAuthError(err) && !err.network) { console.warn('sync', err); } });
		}).then(pruneLog).then(renderLog);
	}

	function renderLog() {
		return allOf('log').then(function (entries) {
			entries.sort(function (a, b) { return a.scanned_at < b.scanned_at ? 1 : -1; });
			var pending = entries.filter(function (e) { return !e.synced; }).length;
			state.pendingCount = pending;
			$('pending').textContent = t('%d offen', pending);
			var log = $('log');
			log.textContent = '';
			entries.slice(0, 25).forEach(function (e) {
				var li = el('li');
				li.appendChild(el('span', fmtTime(e.scanned_at) + ' · …' + e.hash.slice(-8) + ' · ' + resultText(e.decision)));
				var tag = !e.synced ? el('span', t('wartet auf Sync'), 'tag warn') : e.server ? el('span', e.server.conflict ? t('Konflikt') : t('synchronisiert'), 'tag ' + (e.server.conflict ? 'bad' : 'ok')) : el('span', 'offline' === e.mode ? t('offline') : t('online'), 'tag');
				li.appendChild(tag);
				log.appendChild(li);
			});
			var conflicts = $('conflicts');
			conflicts.textContent = '';
			var withConflict = entries.filter(function (e) { return e.server && e.server.conflict; });
			withConflict.forEach(function (e) {
				var li = el('li');
				var text = fmtTime(e.scanned_at) + ' · …' + e.hash.slice(-8) + ' · ' + resultText(e.server.result);
				if (e.server.offline_expired) { text += ' · ' + t('Offline-Dauer überschritten'); }
				li.appendChild(el('span', text));
				li.appendChild(el('span', e.server.auto_resolved ? t('automatisch geklärt') : t('zur Prüfung (#%d)', e.server.conflict_id), 'tag ' + (e.server.auto_resolved ? 'ok' : 'bad')));
				conflicts.appendChild(li);
			});
			if (!withConflict.length) { conflicts.appendChild(el('li', t('Keine Konflikte.'), 'small')); }
		});
	}

	/* ---------------- refresh loop ---------------- */

	function refreshAll(force) {
		if (state.busy || !state.token) { return Promise.resolve(); }
		if (!force && Date.now() - state.lastRefresh < REFRESH_MS) { return syncNow(); }
		state.busy = true;
		return api('GET', 'offline/keys').then(function (res) {
			var serverMs = res.server_time ? Date.parse(res.server_time) : NaN;
			if (isFinite(serverMs)) {
				state.clockOffset = serverMs - Date.now();
				kvSet('clock_offset', state.clockOffset);
				noteServerTime(serverMs, true);
			}
			return Promise.all([setKeys(res.keys), setQrKeys(res.qr_keys)]);
		}).then(function () {
			return api('GET', 'offline/occurrences');
		}).then(function (res) {
			state.occurrences = res.occurrences || [];
			var ids = state.occurrences.map(function (o) { return o.occurrence_id; });
			// Packages of occurrences that are no longer assigned/opted in are removed.
			var stale = Object.keys(state.packages).map(Number).filter(function (id) { return ids.indexOf(id) < 0; });
			stale.forEach(function (id) { delete state.packages[id]; });
			if (stale.length) { idb('packages', 'readwrite', function (s) { stale.forEach(function (id) { s.delete(id); }); }); }
			return ids.reduce(function (chain, id) {
				return chain.then(function () {
					if (!state.token) { return; }
					return refreshPackage(id).catch(function (e) { if (!handleAuthError(e)) { console.warn('package', id, e); } });
				});
			}, Promise.resolve());
		}).then(function () {
			state.lastRefresh = Date.now();
		}, function (err) {
			handleAuthError(err);
		}).then(function () {
			state.busy = false;
			renderOccurrences();
			return syncNow();
		});
	}

	function setNet() {
		var on = navigator.onLine !== false;
		var pill = $('net');
		pill.textContent = on ? t('Online') : t('Offline');
		pill.className = 'pill' + (on ? '' : ' offline');
	}

	/* ---------------- camera ---------------- */

	var camera = { stream: null, detector: null, timer: 0, canvas: null };
	function loadJsQr() {
		if (window.jsQR) { return Promise.resolve(); }
		return new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = '../vendor/jsqr/jsQR.js';
			s.onload = resolve;
			s.onerror = function () { reject(new Error('jsQR')); };
			document.head.appendChild(s);
		});
	}
	function startCamera() {
		if (camera.stream) { stopCamera(); return; }
		var video = $('video');
		navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (stream) {
			camera.stream = stream;
			video.srcObject = stream;
			video.hidden = false;
			$('camera').textContent = t('Kamera stoppen');
			setupTrack(stream);
			return video.play();
		}).then(function () {
			if ('BarcodeDetector' in window) {
				try { camera.detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { camera.detector = null; }
			}
			return camera.detector ? null : loadJsQr();
		}).then(tick).catch(function (e) { showResult('bad', t('Kamera nicht verfügbar'), e && e.message); stopCamera(); });
	}
	/** Torch switch and continuous autofocus where the camera supports them. */
	function setupTrack(stream) {
		var track = stream && stream.getVideoTracks ? stream.getVideoTracks()[0] : null;
		var caps = {};
		try { caps = track && track.getCapabilities ? track.getCapabilities() || {} : {}; } catch (e) { caps = {}; }
		camera.track = track;
		camera.torch = false;
		var btn = $('torch');
		if (btn) { btn.hidden = !caps.torch; btn.setAttribute('aria-pressed', 'false'); }
		if (track && Array.isArray(caps.focusMode) && caps.focusMode.indexOf('continuous') >= 0 && track.applyConstraints) {
			track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});
		}
	}
	function toggleTorch() {
		var track = camera.track;
		if (!track || !track.applyConstraints) { return Promise.resolve(); }
		var next = !camera.torch;
		return track.applyConstraints({ advanced: [{ torch: next }] }).then(function () {
			camera.torch = next;
			var btn = $('torch');
			if (btn) { btn.setAttribute('aria-pressed', next ? 'true' : 'false'); }
		}).catch(function () {});
	}
	function renderSound() {
		var btn = $('sound');
		if (!btn) { return; }
		btn.textContent = state.sound ? t('Ton an') : t('Ton aus');
		btn.setAttribute('aria-pressed', state.sound ? 'true' : 'false');
	}
	function stopCamera() {
		if (camera.stream) { camera.stream.getTracks().forEach(function (t) { t.stop(); }); }
		camera.stream = null;
		camera.track = null;
		if ($('torch')) { $('torch').hidden = true; }
		clearTimeout(camera.timer);
		$('video').hidden = true;
		$('camera').textContent = t('Kamera starten');
	}
	function tick() {
		if (!camera.stream) { return; }
		var video = $('video');
		var next = function () { camera.timer = setTimeout(tick, 250); };
		if (video.readyState < 2) { next(); return; }
		if (camera.detector) {
			camera.detector.detect(video).then(function (codes) {
				if (codes.length) { return handleScan(codes[0].rawValue); }
			}).catch(function () {}).then(next);
			return;
		}
		if (!window.jsQR) { next(); return; }
		camera.canvas = camera.canvas || document.createElement('canvas');
		var c = camera.canvas;
		c.width = video.videoWidth;
		c.height = video.videoHeight;
		var ctx = c.getContext('2d', { willReadFrequently: true });
		ctx.drawImage(video, 0, 0, c.width, c.height);
		var img = ctx.getImageData(0, 0, c.width, c.height);
		var found = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
		(found ? handleScan(found.data) : Promise.resolve()).then(next);
	}

	/* ---------------- pairing ---------------- */

	function pair(code, label) {
		var input = String(code || '').trim();
		// The pairing QR carries JSON {type:"wpcap-pair", api, code}.
		if ('{' === input.charAt(0)) {
			try {
				var qr = JSON.parse(input);
				if ('wpcap-pair' === qr.type && qr.code) {
					if (qr.api && !validApiBase(qr.api)) { throw new Error(t('Der Kopplungscode gehört zu einem anderen Server.')); }
					if (qr.api) { state.api = qr.api; }
					input = qr.code;
				}
			} catch (e) { return Promise.reject(e); }
		}
		state.token = '';
		return api('POST', 'pair', { code: input, device_label: label || t('Offline-Scanner (PWA)'), platform: 'pwa', app_version: APP_VERSION }).then(function (res) {
			state.token = res.token;
			state.deviceId = res.device_id;
			return Promise.all([
				kvSet('api', state.api), kvSet('token', res.token), kvSet('device_id', res.device_id),
				setKeys(res.offline && res.offline.keys), setQrKeys(res.offline && res.offline.qr_keys)
			]);
		});
	}

	function unpair() {
		if (!window.confirm(t('Gerät entkoppeln? Nicht synchronisierte Scans gehen verloren.'))) { return; }
		var logout = state.token ? api('POST', 'logout', {}).catch(function () {}) : Promise.resolve();
		logout.then(function () {
			return Promise.all(['kv', 'packages', 'log'].map(function (s) { return idb(s, 'readwrite', function (st) { st.clear(); }); }));
		}).then(function () { location.reload(); });
	}

	/* ---------------- translations ---------------- */

	/** Applies the current table to the static page (data-i18n*, lang, title) and live labels. */
	function applyI18n() {
		dateFormat = null;
		document.documentElement.lang = lang();
		applyBrand();
		document.title = t('Offline-Scanner') + ' · ' + brandName();
		Array.prototype.forEach.call(document.querySelectorAll('[data-i18n]'), function (n) { n.textContent = t(n.getAttribute('data-i18n')); });
		['placeholder', 'title'].forEach(function (attr) {
			Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-' + attr + ']'), function (n) { n.setAttribute(attr, t(n.getAttribute('data-i18n-' + attr))); });
		});
		if (/\bidle\b/.test($('result').className)) { $('result-title').textContent = t('Bereit'); }
		$('camera').textContent = camera.stream ? t('Kamera stoppen') : t('Kamera starten');
		$('pending').textContent = t('%d offen', state.pendingCount);
		renderSound();
		setNet();
	}

	/** Product name from the server (white label, wp_pass_captain_brand_name_v1); default "Pass Captain". */
	function brandName() {
		var brand = state.i18n && state.i18n.brand;
		return brand && typeof brand.name === 'string' && brand.name ? brand.name : 'Pass Captain';
	}

	/**
	 * White label: with a brand set on the server, links the manifest with the brand name and shows the
	 * logo (same origin only, CSP img-src 'self') in the header. Without a brand nothing changes.
	 */
	function applyBrand() {
		var brand = state.i18n && state.i18n.brand;
		if (!brand || !brand.custom) { return; }
		var manifest = document.querySelector('link[rel="manifest"]');
		if (manifest && state.api) { manifest.setAttribute('href', endpoint('offline/manifest', '')); }
		var bar = document.querySelector('header.bar') || document.querySelector('header');
		var logo = document.getElementById('brand-logo');
		if (bar && typeof brand.logo === 'string' && /^(https?:\/\/|\/(?!\/))/.test(brand.logo)) {
			if (!logo) {
				logo = document.createElement('img');
				logo.setAttribute('id', 'brand-logo');
				logo.setAttribute('class', 'brand-logo');
				bar.insertBefore(logo, bar.firstChild);
			}
			logo.setAttribute('src', brand.logo);
			logo.setAttribute('alt', brandName());
		} else if (logo && logo.parentNode) {
			logo.parentNode.removeChild(logo);
		}
	}

	/** Preferred device languages as a comma separated list of BCP 47 tags (best first, max. 6). */
	function preferredLocales() {
		var list = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || ''];
		return Array.prototype.slice.call(list, 0, 6).filter(function (tag) {
			return typeof tag === 'string' && /^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/.test(tag);
		}).join(',');
	}

	/**
	 * Fetches the table in the device language (public endpoint, no token) and keeps it in IndexedDB
	 * for offline starts. Offline or on errors the stored table (or the German source texts) stays in use.
	 */
	function loadI18n() {
		if (navigator.onLine === false) { return Promise.resolve(); }
		var prefs = preferredLocales();
		return fetch(endpoint('offline/i18n', prefs ? 'locale=' + encodeURIComponent(prefs) : ''), { headers: { 'Accept': 'application/json' }, credentials: 'omit', cache: 'no-cache' }).then(function (res) {
			return res.ok ? res.json() : null;
		}).then(function (table) {
			if (!validI18n(table) || JSON.stringify(table) === JSON.stringify(state.i18n)) { return; }
			state.i18n = table;
			applyI18n();
			if (state.token) { renderOccurrences(); renderLog(); }
			return kvSet('i18n', table);
		}).catch(function () {});
	}

	/* ---------------- boot ---------------- */

	function showView() {
		var paired = !!state.token;
		$('pair-view').hidden = paired;
		$('main-view').hidden = !paired;
		$('api-base').textContent = state.api;
		$('device-id').textContent = state.deviceId;
	}

	function boot() {
		if (!nacl || !window.crypto || !crypto.subtle || !window.indexedDB) {
			showResult('bad', t('Browser nicht unterstützt'), t('Benötigt HTTPS, WebCrypto und IndexedDB.'));
			return;
		}
		if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js', { scope: './' }).catch(function () {}); }
		var param = new URLSearchParams(location.search).get('api');
		Promise.all([kvGet('api'), kvGet('token'), kvGet('device_id'), kvGet('keys'), kvGet('clock_offset'), allOf('packages'), allOf('log'), kvGet('i18n'), kvGet('qr_keys'), kvGet('server_time'), kvGet('hw'), kvGet('sound')]).then(function (v) {
			state.sound = false !== v[11];
			// Stored translations first, so everything below (incl. package errors) is translated offline.
			if (validI18n(v[7])) { state.i18n = v[7]; }
			applyI18n();
			var stored = v[0];
			var paired = !!v[1];
			// ?api= only sets up a device that is not paired yet; a paired device keeps its API base, so a
			// crafted link cannot redirect its token or scans (audit FE-3).
			var useParam = !paired && validApiBase(param);
			var api = useParam ? param : (validApiBase(stored) ? stored : deriveApi());
			state.api = api;
			state.token = v[1] || '';
			state.deviceId = v[2] || '';
			state.keys = v[3] || {};
			state.qrKeys = v[8] || {};
			state.clockOffset = v[4] || 0;
			state.serverTime = Number(v[9]) || 0;
			state.hw = v[10] && typeof v[10] === 'object' ? v[10] : {};
			if (useParam && param !== stored) { kvSet('api', param); }
			v[5].forEach(loadPackage);
			// Local entry counters from this device's scan log (admissions of the last day, pending ones always).
			v[6].slice().sort(function (a, b) { return a.scanned_at < b.scanned_at ? -1 : 1; }).forEach(function (e) {
				if (e.ok && 'out' !== e.direction) { markAdmitted(e.occurrence_id, e.hash, Date.parse(e.scanned_at)); }
			});
			if (param) { history.replaceState(null, '', location.pathname); }
			showView();
			setNet();
			return Promise.all([pruneExpired(), pruneLog()]).then(function () {
				if (state.token) {
					renderOccurrences();
					renderLog();
					refreshAll(true);
				}
				loadI18n();
			});
		});

		$('pair-form').addEventListener('submit', function (ev) {
			ev.preventDefault();
			var btn = ev.target.querySelector('button');
			btn.disabled = true;
			pair($('pair-code').value, $('pair-label').value.trim()).then(function () {
				showView();
				renderOccurrences();
				showResult('ok', t('Gekoppelt'), t('Jetzt im Admin unter „Offline-Scanner“ dem Event zuweisen.'));
				return refreshAll(true);
			}).catch(function (err) {
				showResult('bad', t('Kopplung fehlgeschlagen'), err && err.message);
				$('main-view').hidden = true;
			}).then(function () { btn.disabled = false; });
		});
		$('occurrence').addEventListener('change', function (ev) { state.selected = Number(ev.target.value) || 0; renderPackage(); });
		$('area').addEventListener('change', function (ev) { state.areaId = Number(ev.target.value) || 0; });
		$('refresh').addEventListener('click', function () { refreshAll(true); });
		$('sync').addEventListener('click', function () { syncNow(); });
		$('camera').addEventListener('click', startCamera);
		if ($('torch')) { $('torch').addEventListener('click', toggleTorch); }
		if ($('sound')) {
			$('sound').addEventListener('click', function () {
				state.sound = !state.sound;
				renderSound();
				kvSet('sound', state.sound).catch(function () {});
			});
		}
		$('unpair').addEventListener('click', unpair);
		$('manual').addEventListener('submit', function (ev) {
			ev.preventDefault();
			lastScan = { code: '', at: 0 };
			handleScan($('manual-code').value).then(function () { $('manual-code').value = ''; });
		});
		window.addEventListener('online', function () { setNet(); refreshAll(true); loadI18n(); });
		window.addEventListener('offline', setNet);
		// The camera is released when the app is hidden or left (no running camera in the background).
		window.addEventListener('pagehide', stopCamera);
		document.addEventListener('visibilitychange', function () { if ('hidden' === document.visibilityState) { stopCamera(); } });
		setInterval(function () {
			if (navigator.onLine !== false) { refreshAll(false); }
			Promise.all([pruneExpired(), pruneLog()]).then(function (r) {
				if (r[0].length) { renderOccurrences(); } else { renderPackage(); }
			});
		}, 30000);
	}

	/**
	 * Read view of the state for diagnostics and tests: the device token is hidden (not readable, not
	 * listed, not writable through this view); all other fields pass through.
	 */
	var publicState = typeof Proxy === 'function' ? new Proxy(state, {
		get: function (target, prop) { return 'token' === prop ? undefined : target[prop]; },
		set: function (target, prop, value) { if ('token' !== prop) { target[prop] = value; } return true; },
		has: function (target, prop) { return 'token' !== prop && prop in target; },
		ownKeys: function (target) { return Reflect.ownKeys(target).filter(function (k) { return 'token' !== k; }); },
		getOwnPropertyDescriptor: function (target, prop) { return 'token' === prop ? undefined : Object.getOwnPropertyDescriptor(target, prop); }
	}) : {};

	// Exposed for diagnostics/tests only (no secrets in here).
	window.wpcapOffline = { serviceDay: serviceDay, beep: beep, showIdle: showIdle, toggleTorch: toggleTorch, setupTrack: setupTrack, refreshPackage: refreshPackage, t: t, fmtTime: fmtTime, normalizeCode: normalizeCode, signedCode: signedCode, verifySignedQr: verifySignedQr, decideSigned: decideSigned, verifyDoc: verifyDoc, buildIndex: buildIndex, loadPackage: loadPackage, packageStatus: packageStatus, decideOffline: decideOffline, validI18n: validI18n, applyI18n: applyI18n, handleScan: handleScan, pair: pair, state: publicState, syncNow: syncNow, refreshAll: refreshAll, validApiBase: validApiBase, pruneExpired: pruneExpired, pruneLog: pruneLog, qrWindow: qrWindow };

	boot();
}());
