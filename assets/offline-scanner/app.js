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
 * without a table the static catalog of i18n.js (?lang=, A045) and then the German source texts are used.
 *
 * Scan feedback: a scan first shows the neutral "Prüfe …" state; the result is shown full screen for
 * a hold time per kind and then returns to "Bereit" (A548); a code that stays in view is ignored until
 * it was out of view for 3 s (A539). A code this device admitted a moment ago shows yellow "Eben hier
 * eingelassen (vor X s)" instead of red. Tones (WebAudio, switchable) and vibration accompany every
 * result; "Rückgängig" is offered for 30 s after an admission (A547).
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
	// One IndexedDB database per API base (A473): the sites of a subdirectory network share the
	// origin of the plugin URL, so a single database mixed device token, keys, packages and the scan
	// log of different sites. DB_NAME stays the store of a pairing made before (its stored "api").
	var DB_NAME = 'wpcap-offline-scanner';
	var CURRENT_API_KEY = 'wpcap-offline-scanner-api';
	var MAX_DELTAS = 30;
	var REFRESH_MS = 60000;
	var SYNC_BATCH = 200;
	var LOG_MAX_AGE_MS = 24 * 3600e3; // synced log entries are kept for a day ...
	var LOG_MAX_ENTRIES = 500; // ... and at most this many (pending scans are always kept)
	var DAY_S = 86400;
	var SAME_CODE_LOCK_MS = 3000; // the same code (held in front of the camera) is ignored until out of view this long (A539)
	var RECENT_MS = 300000; // "Eben hier eingelassen": local admissions of the last 5 minutes
	var PACKAGE_TIMEOUT_MS = 120000; // full packages of large events need time on weak networks
	var PACKAGE_FORMAT = 2; // compact package format (format 1 is still read)
	var HASH_KEY = 32; // hex digits of the ticket hash used as index key (= hash prefix of format 2)
	var nacl = window.nacl;
	// Static catalog (i18n.js): fallback of t() below until the server table is loaded.
	var I18N = window.WPCAP_I18N || { t: function (x) { return x; }, locale: 'de-DE', apply: function () {} };

	var state = {
		api: '', token: '', deviceId: '', keys: {}, qrKeys: {}, clockOffset: 0,
		serverTime: 0, // last known server time (ms): lower bound for now(), the device clock may be set back
		hw: {}, // occurrence_id -> { cursor, issued_at } of the newest accepted package (rollback protection)
		occurrences: [], selected: 0, areaId: 0,
		legacyApi: '', // API base of the database of versions before A473
		packages: {}, // occurrence_id -> { record, index: Map, meta, error }
		admitted: {}, // occurrence_id -> Set of hash keys admitted on this device
		local: {}, // occurrence_id -> Map hash key -> { ins: [ms of admissions], inside: bool } (entry counters)
		busy: false, lastRefresh: 0, syncing: false, pendingCount: 0,
		i18n: null, // { v, locale, lang, strings } from GET /app/offline/i18n
		serverCounts: {}, pendingIn: {}, // A546: occurrence_id -> {admitted, total, at} / unsynced offline admissions
		scanHash: '', // A548: hash of the scan being shown (short code in the overlay)
		undo: null, undoTimer: 0, // A547: current undo offer { key, started, occurrenceId, hash, area, publicId, open }
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
	var dbName = DB_NAME;
	function openDb(name) {
		return new Promise(function (resolve, reject) {
			var req = indexedDB.open(name, 1);
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
	}
	function db() {
		if (!dbPromise) { dbPromise = openDb(dbName); }
		return dbPromise;
	}
	/** Name of the database of an API base: the pre-A473 database when it belongs to that base. */
	function storeName(apiBase, legacyApi) {
		return !apiBase || apiBase === legacyApi ? DB_NAME : DB_NAME + '@' + apiBase;
	}
	/**
	 * Works with the data of the site behind apiBase from now on (A473): its own database, so its
	 * own pairing, keys, packages and scan log; remembered as the site of the next start without
	 * ?api= (home screen icon).
	 */
	function useSite(apiBase) {
		var name = storeName(apiBase, state.legacyApi);
		if (name !== dbName) {
			if (dbPromise) { dbPromise.then(function (d) { if (d.close) { d.close(); } }, function () {}); }
			dbPromise = null;
			dbName = name;
			state.token = ''; state.deviceId = ''; state.keys = {}; state.packages = {}; state.admitted = {}; state.occurrences = []; state.selected = 0;
		}
		state.api = apiBase;
		try { localStorage.setItem(CURRENT_API_KEY, apiBase); } catch (e) { /* only for this visit */ }
	}
	/** API base stored in the database of earlier versions ('' when there is none). */
	function legacyApiBase() {
		return openDb(DB_NAME).then(function (d) {
			return new Promise(function (resolve) {
				var done = function (value) { if (d.close) { d.close(); } resolve(value); };
				try {
					var req = d.transaction('kv', 'readonly').objectStore('kv').get('api');
					req.onsuccess = function () { done(req.result || ''); };
					req.onerror = function () { done(''); };
				} catch (e) { done(''); }
			});
		}).catch(function () { return ''; });
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
	/**
	 * Same rule as PassCaptain\Checkin::normalize_code(): exactly one 64-hex code, lowercase. A longer
	 * hex run or two different codes in one input (codes typed behind each other) give '' (A271).
	 */
	function scanCode(value) {
		var runs = String(value || '').match(/[a-f0-9]{64,}/gi) || [];
		var codes = runs.map(function (r) { return r.toLowerCase(); }).filter(function (r, i, all) { return all.indexOf(r) === i; });
		if (!codes.length) { return { code: '', ambiguous: false }; }
		if (codes.length > 1 || 64 !== codes[0].length) { return { code: '', ambiguous: true }; }
		return { code: codes[0], ambiguous: false };
	}
	function normalizeCode(value) { return scanCode(value).code; }
	/** Same rule as PassCaptain\HelpCode::normalize(): 8 characters without 0/O/1/I, '' otherwise. */
	function normalizeHelpCode(value) {
		var v = String(value || '').toUpperCase().replace(/[\s\-_.]+/g, '');
		return /^[2-9A-HJ-NP-Z]{8}$/.test(v) ? v : '';
	}
	function uuid() {
		if (crypto.randomUUID) { return crypto.randomUUID(); }
		var b = crypto.getRandomValues(new Uint8Array(16));
		return hex(b.buffer);
	}
	/* ---------------- i18n ---------------- */

	var I18N_VERSION = 1;

	/**
	 * Translation of a German source text: the server table (GET /app/offline/i18n, any language with a
	 * WordPress translation) first, then the static catalog of i18n.js (works on the very first offline
	 * start), else the German source. Placeholders: printf-style %s/%d/%1$s and the %1 … of i18n.js.
	 */
	function t(key) {
		var table = state.i18n && state.i18n.strings;
		var text = table && Object.prototype.hasOwnProperty.call(table, key) && typeof table[key] === 'string' ? table[key] : String(I18N.t(key));
		var args = Array.prototype.slice.call(arguments, 1);
		if (!args.length) { return text; }
		var next = 0;
		return text.replace(/%(?:(\d+)\$)?([sd%])|%(\d)/g, function (m, pos, type, bare) {
			if ('%' === type) { return '%'; }
			var v = args[bare ? Number(bare) - 1 : (pos ? Number(pos) - 1 : next++)];
			if (v === undefined || v === null) { return ''; }
			return 'd' === type ? String(Math.trunc(Number(v)) || 0) : String(v);
		});
	}
	function lang() { return (state.i18n && state.i18n.lang) || I18N.locale || 'de-DE'; }
	/** Accepts only well-formed tables of the known format version. */
	function validI18n(table) {
		return !!(table && I18N_VERSION === table.v && typeof table.lang === 'string' && table.strings && typeof table.strings === 'object');
	}
	// A516: times of an occurrence are shown in the occurrence's time zone (not the device's); the zone
	// abbreviation is added when it differs from the device zone at that moment.
	function fmtTime(iso, zone) {
		var d = new Date(iso);
		if (isNaN(d)) { return '—'; }
		var opts = { dateStyle: 'short', timeStyle: 'short' };
		if (zone) {
			try {
				var device = new Intl.DateTimeFormat('en-US', { timeZoneName: 'longOffset' }).format(d);
				var there = new Intl.DateTimeFormat('en-US', { timeZoneName: 'longOffset', timeZone: zone }).format(d);
				var text = new Intl.DateTimeFormat(lang(), { dateStyle: 'short', timeStyle: 'short', timeZone: zone }).format(d);
				if (device.split(' ').pop() !== there.split(' ').pop()) {
					var part = new Intl.DateTimeFormat(lang(), { timeZone: zone, timeZoneName: 'short' }).formatToParts(d).filter(function (p) { return 'timeZoneName' === p.type; })[0];
					text += ' ' + (part ? part.value : zone);
				}
				return text;
			} catch (e) { /* unknown zone: device time below */ }
		}
		try { return new Intl.DateTimeFormat(lang(), opts).format(d); } catch (e) { return new Intl.DateTimeFormat('de-DE', opts).format(d); }
	}
	/** Time zone of an occurrence (package manifest or assignment list), '' when unknown. */
	function zoneOf(occurrenceId) {
		var p = state.packages[occurrenceId];
		if (p && p.meta && p.meta.occurrence && p.meta.occurrence.timezone) { return p.meta.occurrence.timezone; }
		var o = (state.occurrences || []).filter(function (x) { return Number(x.occurrence_id) === Number(occurrenceId); })[0];
		return o && o.timezone ? o.timezone : '';
	}
	function el(tag, text, cls) {
		var e = document.createElement(tag);
		if (text !== undefined && text !== null) { e.textContent = String(text); }
		if (cls) { e.className = cls; }
		return e;
	}

	/** German source texts of the result codes (keys of the i18n table); translated on lookup. */
	var RESULT_TEXT = {
		checked_in: 'Einlass OK', already_checked_in: 'Bereits verwendet', revoked: 'Widerrufen', replaced: 'Code ersetzt', unknown: 'Unbekanntes Ticket',
		wrong_event: 'Falsches Event', wrong_occurrence: 'Falscher Termin', wrong_area: 'Falscher Einlassbereich',
		cancelled_event: 'Termin abgesagt', out_of_window: 'Außerhalb des Einlasszeitraums', not_valid_today: 'Heute nicht gültig',
		daily_limit_reached: 'Tageskontingent erreicht', entry_limit_reached: 'Einlasslimit erreicht', outside_entry_window: 'Außerhalb des Einlassfensters',
		already_inside: 'Bereits drinnen', area_full: 'Bereich voll', undone: 'Einlass zurückgenommen', forbidden: 'Kein Einlasszugriff', package_expired: 'Prüfpaket abgelaufen', no_package: 'Kein gültiges Prüfpaket',
		invalid_code: 'Kein Ticketcode erkannt', invalid_signature: 'QR-Signatur ungültig', retry: 'Bitte erneut scannen', rate_limited: 'Zu viele Hilfscode-Eingaben'
	};
	function resultText(code) { return Object.prototype.hasOwnProperty.call(RESULT_TEXT, code) ? t(RESULT_TEXT[code]) : code; }

	/* Sound (WebAudio, can be switched off per device) and vibration, like the web scanner. */
	var sound = { on: true, ctx: null };
	try { sound.on = localStorage.getItem('wpcap_scanner_sound') !== 'off'; } catch (e) { sound.on = true; }
	function unlockAudio() {
		var Ctx = window.AudioContext || window.webkitAudioContext;
		if (!sound.on || sound.ctx || !Ctx) { return; }
		try { sound.ctx = new Ctx(); } catch (e) { sound.ctx = null; }
	}
	function tone(frequencies, length, type) {
		if (!sound.on || !sound.ctx) { return; }
		try {
			if ('suspended' === sound.ctx.state) { sound.ctx.resume(); }
			var at = sound.ctx.currentTime;
			frequencies.forEach(function (f) {
				var osc = sound.ctx.createOscillator();
				var gain = sound.ctx.createGain();
				osc.type = type || 'sine';
				osc.frequency.value = f;
				gain.gain.setValueAtTime(0.2, at);
				gain.gain.exponentialRampToValueAtTime(0.001, at + length);
				osc.connect(gain).connect(sound.ctx.destination);
				osc.start(at);
				osc.stop(at + length + 0.02);
				at += length + 0.04;
			});
		} catch (e) { /* no sound */ }
	}
	/** Result tone: high = admitted, double = warning, low buzz = rejected. */
	function beep(kind) {
		unlockAudio();
		if ('ok' === kind) { tone([660, 990], 0.12); } else if ('bad' === kind) { tone([196, 147], 0.32, 'square'); } else if ('warn' === kind) { tone([440, 440], 0.15, 'triangle'); }
	}
	function renderSound() {
		var btn = $('sound');
		if (!btn) { return; }
		btn.setAttribute('aria-pressed', sound.on ? 'true' : 'false');
		btn.textContent = sound.on ? t('Ton an') : t('Ton aus');
	}
	function toggleSound() {
		sound.on = !sound.on;
		try { localStorage.setItem('wpcap_scanner_sound', sound.on ? 'on' : 'off'); } catch (e) { /* only for this visit */ }
		if (sound.on) { unlockAudio(); tone([660], 0.1); }
		renderSound();
	}

	/*
	 * A548: every result is shown full screen for a short hold time (green 2.5 s, yellow 5 s, red 8 s or
	 * until tapped) with the time and the short code of the scanned ticket, then the result box goes
	 * back to "Bereit" (green after 6 s, otherwise after 15 s) – an old verdict is never read as the
	 * current one. The overlay never takes focus; the result box stays the live region. While a scan is
	 * checked the box shows the neutral "Prüfe …" state.
	 */
	var HOLD = { ok: { overlay: 2500, reset: 6000 }, warn: { overlay: 5000, reset: 15000 }, bad: { overlay: 8000, reset: 15000 } };
	function holdTimes(kind) { return HOLD[kind] || HOLD.warn; }
	var ICONS = { ok: '✓', bad: '✕', warn: '!' };
	var display = { overlayTimer: 0, resetTimer: 0, idle: true };
	/** Short code shown with a result: the last 6 characters of the code hash (no code is kept). */
	function shortCode(hash) { return hash ? '…' + String(hash).slice(-6).toUpperCase() : ''; }
	function hideOverlay() {
		clearTimeout(display.overlayTimer);
		var o = $('overlay');
		if (o) { o.hidden = true; }
	}
	function resetResult() {
		clearTimeout(display.resetTimer);
		if (state.undo && state.undo.open) { display.resetTimer = setTimeout(resetResult, 5000); return; }
		display.idle = true;
		$('result').className = 'result idle';
		$('result-title').textContent = t('Bereit');
		$('result-detail').textContent = '';
	}
	/** Back to "Bereit" (alias of resetResult() for older callers). */
	function showIdle() { hideOverlay(); resetResult(); }
	function showOverlay(kind, title, detail) {
		var o = $('overlay');
		if (!o) { return; }
		o.className = 'overlay ' + kind;
		$('overlay-icon').textContent = ICONS[kind] || '';
		$('overlay-title').textContent = title;
		$('overlay-detail').textContent = detail || '';
		var meta = [new Date(now()).toLocaleTimeString(lang(), { hour: '2-digit', minute: '2-digit', second: '2-digit' }), shortCode(state.scanHash)].filter(Boolean).join(' · ');
		$('overlay-meta').textContent = meta;
		o.hidden = false;
		clearTimeout(display.overlayTimer);
		display.overlayTimer = setTimeout(hideOverlay, holdTimes(kind).overlay);
	}
	/**
	 * Shows a result: kind ok (green), warn (yellow), bad (red) or pending (neutral, while checking).
	 * Every result goes back to "Bereit" after its hold time, so the next guest never sees the previous
	 * guest's result. (The fourth argument of older callers is ignored.)
	 */
	function showResult(kind, title, detail) {
		// A new result ends the undo offer of the previous admission (A547).
		if (state.undo && !state.undo.open) { state.undo = null; renderUndo(); }
		var box = $('result');
		display.idle = false;
		box.className = 'result ' + kind;
		$('result-title').textContent = title;
		$('result-detail').textContent = detail || '';
		clearTimeout(display.resetTimer);
		if ('pending' === kind) { hideOverlay(); return; }
		showOverlay(kind, title, detail);
		state.scanHash = '';
		display.resetTimer = setTimeout(resetResult, holdTimes(kind).reset);
		beep(kind);
		try { if (navigator.vibrate) { navigator.vibrate('ok' === kind ? 80 : ('warn' === kind ? [120, 80, 120] : [250, 100, 250, 100, 250])); } } catch (e) { /* no vibration */ }
	}
	/** "Erstmals eingelassen" / "Bereits eingelassen um 19:42" from the server's admission history. */
	function historyText(res) {
		if (!res) { return ''; }
		if (res.ok) {
			if (res.first_admission) { return t('Erstmals eingelassen'); }
			return res.admissions > 1 && res.first_checked_in_at ? t('Einlass Nr. %1 – zuerst eingelassen um %2', res.admissions, fmtTime(res.first_checked_in_at, res.timezone || zoneOf(state.selected))) : '';
		}
		if (!res.first_checked_in_at) { return ''; }
		return t('Bereits eingelassen um %1', fmtTime(res.first_checked_in_at, res.timezone || zoneOf(state.selected))) + (res.first_checked_in_by ? ' ' + t('durch %1', res.first_checked_in_by) : '') + (res.last_checked_in_area ? ' · ' + res.last_checked_in_area : ''); // A595: device or person
	}
	function onlineDetail(res) {
		// A543: refusals carry the server's reason ("Code ersetzt: … übertragen …", "widerrufen: umgetauscht").
		var reason = !res.ok && 'string' === typeof res.message ? res.message : '';
		return [reason, res.ticket, res.attendee_name, historyText(res), res.staff_hint ? '⚠ ' + res.staff_hint : '', res.help_code ? '⚠ ' + t('Per Hilfscode geprüft') : '', t('online')].filter(Boolean).join(' · ');
	}

	/* ---------------- API ---------------- */

	function deriveApi() {
		var path = location.pathname;
		// Served from the site (A286: …/wpcap-offline-scanner/app/, plain permalinks …/index.php/wpcap-offline-scanner/app/) or from the plugin folder.
		var i = path.indexOf('/wpcap-offline-scanner/');
		if (i < 0) { i = path.indexOf('/wp-content/'); }
		var base = i >= 0 ? path.slice(0, i) : '';
		if (/\/index\.php$/.test(base)) { return location.origin + base.slice(0, -10) + '/?rest_route=/pass-captain/v1/app/'; }
		return location.origin + base + '/wp-json/pass-captain/v1/app/';
	}
	var API_ROUTES = ['/pass-captain/v1/app/', '/wp-pass-captain/v1/app/'];
	/**
	 * A649: an API base (from ?api=, the pairing QR code or storage) must be the app API of this site –
	 * same origin (the token must never go to another host) and the path …/wp-json/pass-captain/v1/app/ (or the legacy namespace), or the plain
	 * permalink form ?rest_route=/pass-captain/v1/app/ – so the device token never goes to another
	 * path of the same host (an upload, another plugin's route, a page that logs headers).
	 */
	function apiBaseOk(url) {
		var u;
		try { u = new URL(String(url || ''), location.href); } catch (e) { return false; }
		if (u.origin !== location.origin || u.username || u.password || u.hash) { return false; }
		if (u.search) {
			var keys = [];
			u.searchParams.forEach(function (value, key) { keys.push(key); });
			return 1 === keys.length && 'rest_route' === keys[0] && API_ROUTES.indexOf(u.searchParams.get('rest_route')) >= 0 && /\/(index\.php)?$/.test(u.pathname);
		}
		return API_ROUTES.some(function (route) { var suffix = '/wp-json' + route; return u.pathname.slice(-suffix.length) === suffix; });
	}
	/** A usable API base (string, same origin, the plugin's app namespace): see apiBaseOk(). */
	function validApiBase(url) {
		return typeof url === 'string' && !!url && apiBaseOk(url);
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

	/** invalid: the answer was no JSON object (login/proxy/WAF page), whatever the HTTP status. */
	function ApiError(status, body, invalid) { this.status = status; this.body = body || {}; this.invalid = !!invalid; this.message = (body && body.message) || ('HTTP ' + status); }

	/**
	 * Classifies the answer to an online scan (A315): 'ok' / 'bad' only for a scan result of the plugin
	 * (JSON with ok and a wpcap_* code for refusals); 'offline' for no answer, timeouts and server
	 * outages (the offline decision follows); 'auth' / 'rate' for 401 / 429; everything else (WAF or
	 * proxy page, unknown error, retryable server error, answer without a scan result) is 'warn':
	 * a yellow system warning, never "no entry".
	 */
	function classifyScan(res, err) {
		if (err) {
			if (err.network || err.status >= 500) { return 'offline'; }
			if (401 === err.status) { return 'auth'; }
			if (429 === err.status) { return 'rate'; }
			var code = err.body && 'string' === typeof err.body.code ? err.body.code : '';
			return !err.invalid && 0 === code.indexOf('wpcap_') && !/retry|busy/.test(code) ? 'bad' : 'warn';
		}
		if (!res || 'object' !== typeof res) { return 'warn'; }
		if (true === res.ok && 'string' === typeof res.result) { return 'ok'; }
		var rc = 'string' === typeof res.code ? res.code : '';
		if (false === res.ok && 0 === rc.indexOf('wpcap_') && !res.retryable && !/retry|busy/.test(rc)) { return 'bad'; }
		return 'warn';
	}

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
				return readJson(res, res.ok ? onProgress : null).catch(function () { return null; }).then(function (data) {
					if (timer) { clearTimeout(timer); }
					var invalid = !data || 'object' !== typeof data || Array.isArray(data);
					if (!res.ok || invalid) { throw new ApiError(res.status, invalid ? {} : data, invalid); }
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
		// A543: prefixes of replaced codes (the ticket has a new code) – shown as "Code ersetzt".
		var replaced = {};
		if (typeof payload.replaced === 'string' && !(payload.replaced.length % size) && /^[a-f0-9]*$/.test(payload.replaced)) {
			for (var r = 0; r < payload.replaced.length; r += size) { replaced[payload.replaced.slice(r, r + size)] = true; }
		}
		var out = [];
		payload.rows.forEach(function (row) {
			var status = Array.isArray(row) ? STATUS_OF_DIGIT[row[1]] : undefined;
			if (!status || typeof row[2] !== 'string' || row[2].length % size || !/^[a-f0-9]*$/.test(row[2])) { throw new Error(t('Unbekanntes Paketformat')); }
			for (var i = 0; i < row[2].length; i += size) {
				var h = row[2].slice(i, i + size);
				var entry = { h: h, s: status };
				if ('revoked' !== status) { entry.t = row[0]; } else if (replaced[h]) { entry.r = 'replaced'; }
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
				list.push({ occurrence_id: id, event: p.meta.event.name, starts_at: p.meta.occurrence.starts_at, timezone: p.meta.occurrence.timezone || '' });
			}
		});
		if (!list.length) {
			sel.appendChild(el('option', t('Keine zugewiesenen Termine'), ''));
		}
		list.forEach(function (o) {
			var opt = el('option', o.event + ' – ' + fmtTime(o.starts_at, o.timezone || zoneOf(o.occurrence_id)));
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
				m.event.name + ' · ' + fmtTime(m.occurrence.starts_at, m.occurrence.timezone),
				t('%1$d Tickets im Paket · signiert (Ed25519, Schlüssel %2$s) · Stand %3$s', valid, m.key_id, fmtTime(m.issued_at, m.occurrence.timezone)),
				t('Gültig bis %1$s · Doppelscans: %2$s', fmtTime(m.expires_at, m.occurrence.timezone), 'first_sync_wins' === m.rules.duplicate_policy ? t('erste Synchronisierung gewinnt') : t('zur Prüfung'))
			];
			lines.forEach(function (l) { box.appendChild(el('div', l)); });
			renderAreas(m);
		} else {
			$('area-wrap').hidden = true;
		}
		renderCounter();
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
		// A543: a hash that went away because the ticket got a new code is "Code ersetzt".
		if ('revoked' === t.s) { return { ok: false, result: 'replaced' === t.r ? 'replaced' : 'revoked' }; }
		var type = null;
		entry.meta.ticket_types.forEach(function (tt) { if (tt.id === t.t) { type = tt; } });
		// Doors-open time of the occurrence: nobody is admitted before it.
		var doors = entry.meta.occurrence && entry.meta.occurrence.doors_open_at ? Date.parse(entry.meta.occurrence.doors_open_at) : NaN;
		if (!isNaN(doors) && now() < doors) { return { ok: false, result: 'outside_entry_window', ticket: t, type: type }; }
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
			// A595: offline the server history is missing; show when this device admitted the ticket (occurrence time zone).
			var ins = LOCAL_REPEAT.indexOf(result) >= 0 ? localOf(occurrenceId, hash).ins : [];
			var lines = ins.length ? [t('Auf diesem Gerät eingelassen um %1', fmtTime(new Date(Math.max.apply(null, ins)).toISOString(), zoneOf(occurrenceId)))].concat(details) : details;
			showResult('bad', resultText(result), lines.filter(Boolean).join(' · '), true);
		}
	}

	/**
	 * Typed help code ("K7QM-4XWA") when the QR code cannot be read. Only the server can resolve it
	 * (the package holds no help codes), so it is checked online only and never decided offline.
	 */
	function handleHelpCode(help) {
		var occurrenceId = state.selected;
		var entry = state.packages[occurrenceId];
		var offlineMsg = function () { showResult('warn', t('Hilfscode nur online'), t('Hilfscodes können nur online geprüft werden. Bitte QR-Code scannen oder den langen Ticket-Code eingeben.')); };
		if (navigator.onLine === false) { offlineMsg(); return Promise.resolve(); }
		var key = uuid();
		var scannedAt = new Date(now()).toISOString();
		var area = (entry && entry.meta && entry.meta.device_area_id) || state.areaId || 0;
		return api('POST', 'scan/check-in', { code: help, idempotency_key: key, occurrence_id: occurrenceId, area_id: area }, null, 5000).then(function (res) {
			var kind = classifyScan(res, null);
			if ('warn' === kind) { systemWarning(res); return; }
			showResult(kind, resultText(res.result), onlineDetail(res));
			return sha256Hex('help:' + help).then(function (hash) {
				return put('log', { key: key, occurrence_id: occurrenceId, hash: hash, code: '', scanned_at: scannedAt, direction: 'in', area_id: area, mode: 'online', decision: res.result, ok: !!res.ok, synced: 1 });
			});
		}, function (err) {
			var kind = classifyScan(null, err || {});
			if ('offline' === kind) { offlineMsg(); return; }
			if (handleAuthError(err)) { return; }
			if ('rate' === kind) { showResult('warn', resultText('rate_limited'), err.message); return; }
			if ('bad' === kind) { showResult('bad', t('Fehler'), err.message); return; }
			systemWarning(err);
		}).then(renderLog);
	}

	/** Yellow system warning: the ticket was not checked (A315). source: ApiError or a scan answer. */
	function systemWarning(source) {
		var status = source && source.status ? ' (HTTP ' + source.status + ')' : '';
		var message = source && !source.invalid && source.body && source.body.message ? source.body.message : (source && source.message && !source.status ? source.message : '');
		showResult('warn', t('Systemfehler – Ticket nicht geprüft'), [message, t('Bitte erneut scannen.') + status].filter(Boolean).join(' · '));
	}

	var lastScan = { code: '', at: 0 };
	function handleScan(raw) {
		unlockAudio();
		var signed = signedCode(raw);
		var parsed = signed ? { code: signed, ambiguous: false } : scanCode(raw);
		var code = parsed.code;
		if (!code) {
			if (parsed.ambiguous) {
				showResult('bad', t('Mehrere Ticketcodes erkannt'), t('Nichts geprüft. Bitte jedes Ticket einzeln scannen.'));
				return Promise.resolve();
			}
			var help = normalizeHelpCode(raw);
			if (help && state.selected) { return handleHelpCode(help); }
			showResult('bad', help ? t('Kein Termin gewählt') : resultText('invalid_code'));
			return Promise.resolve();
		}
		// Sliding window (A539, like the web scanner): a code that stays in view is ignored until it was
		// out of view for 3 s, so the guest just admitted never gets "already used" a moment later.
		if (code === lastScan.code && Date.now() - lastScan.at < SAME_CODE_LOCK_MS) { lastScan.at = Date.now(); return Promise.resolve(); }
		lastScan = { code: code, at: Date.now() };
		var occurrenceId = state.selected;
		var entry = state.packages[occurrenceId];
		if (!occurrenceId) { showResult('bad', t('Kein Termin gewählt')); return Promise.resolve(); }
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
			state.scanHash = hash; // A548: short code in the full-screen result
			/** Offline decision against the verified package (network errors, timeouts, outages). */
			var decideLocally = function () {
				var d = signed ? decideSigned(entry, code) : decideOffline(entry, hash);
				if (signed && d.hash) { hash = d.hash; }
				var typeName = d.type ? d.type.name : '';
				var hint = d.type && d.type.hint ? '⚠ ' + d.type.hint : '';
				var name = d.ticket && d.ticket.n ? d.ticket.n : '';
				if (d.ok) {
					markAdmitted(occurrenceId, hash, Date.parse(scannedAt));
					showResult('ok', t('Einlass OK (offline)'), [typeName, name, hint, d.multi ? t('Mehrfachticket – Prüfung beim Sync') : ''].filter(Boolean).join(' · '));
					offerUndo({ key: key, occurrenceId: occurrenceId, hash: hash, area: area });
				} else {
					showRejected(occurrenceId, hash, d.result, [typeName, name, t('offline')], d.recent === undefined ? -1 : d.recent);
				}
				// Only admitted scans are uploaded; the code is kept until the upload succeeded.
				return put('log', { key: key, occurrence_id: occurrenceId, hash: hash, code: d.ok ? code : '', scanned_at: scannedAt, direction: 'in', area_id: area, mode: 'offline', decision: d.result, ok: d.ok, synced: d.ok ? 0 : 1 });
			};
			var online = navigator.onLine !== false;
			var attempt = online
				? api('POST', 'scan/check-in', { code: code, idempotency_key: key, occurrence_id: occurrenceId, area_id: area }, null, 5000)
				: Promise.reject({ network: true });
			return attempt.then(function (res) {
				// An old server answering a database retry with HTTP 200: decide offline like an outage.
				if (res && false === res.ok && 'retry' === res.result) { return decideLocally(); }
				var kind = classifyScan(res, null);
				if ('warn' === kind) { systemWarning(res); return; }
				var ok = 'ok' === kind;
				if (ok) {
					markAdmitted(occurrenceId, hash, Date.parse(scannedAt));
					showResult(kind, resultText(res.result), onlineDetail(res));
				} else {
					showRejected(occurrenceId, hash, res.result, [onlineDetail(res)]);
				}
				// A546: a first admission counts at once (the server value is fetched at most every 10 s).
				if (ok && res.first_admission && state.serverCounts[occurrenceId]) { state.serverCounts[occurrenceId].admitted++; }
				if (ok) { offerUndo({ key: key, occurrenceId: occurrenceId, hash: hash, area: area, publicId: res.ticket_public_id }); }
				return put('log', { key: key, occurrence_id: occurrenceId, hash: hash, code: '', scanned_at: scannedAt, direction: 'in', area_id: area, mode: 'online', decision: res.result, ok: ok, synced: 1 });
			}, function (err) {
				// Network errors, timeouts, server outages and database retries (HTTP 503) fall back to the
				// offline decision; only a wpcap_* error of the plugin is a refusal, anything else a system
				// warning (A315).
				var kind = classifyScan(null, err || {});
				if ('offline' !== kind) {
					if (handleAuthError(err)) { return; }
					if ('rate' === kind) { showResult('warn', resultText('rate_limited'), err.message); return; }
					if ('bad' === kind) { showResult('bad', t('Fehler'), err.message); return; }
					systemWarning(err);
					return;
				}
				return decideLocally();
			});
		}).then(renderLog).then(refreshCounts);
	}

	/* ---------------- manual search (A544) ---------------- */

	/**
	 * Offline search in the verified package: attendee names (only shipped when the event allows it,
	 * rules.attendee_names), at least 3 characters, at most 10 hits. Order numbers and e-mails are not
	 * in the package – they are searched online only.
	 */
	function searchPackage(entry, q) {
		var needle = String(q || '').trim().toLowerCase();
		if (needle.length < 3 || !entry || !entry.index) { return []; }
		var types = {};
		(entry.meta && entry.meta.ticket_types || []).forEach(function (tt) { types[tt.id] = tt.name; });
		var out = [];
		entry.index.forEach(function (tk, hash) {
			// hash: the full sha256 from format-1 packages, the 32-hex prefix from compact ones (the server accepts both).
			if (out.length < 10 && tk.n && String(tk.n).toLowerCase().indexOf(needle) >= 0) { out.push({ hash: tk.h || hash, attendee_name: tk.n, ticket: types[tk.t] || '', status: 'revoked' === tk.s ? 'revoked' : tk.s }); }
		});
		return out;
	}
	function searchLine(item) {
		return [item.attendee_name, item.ticket, item.email, item.order_ref ? t('Bestellung %1', item.order_ref) : '', 'valid' === item.status ? '' : ('checked_in' === item.status ? t('bereits eingecheckt') : t('widerrufen/erstattet'))].filter(Boolean).join(' · ');
	}
	function renderSearch(items, note) {
		var list = $('search-results');
		list.textContent = '';
		if (note) { list.appendChild(el('li', note, 'small')); }
		if (!items.length && !note) { list.appendChild(el('li', t('Kein Ticket gefunden.'), 'small')); }
		items.forEach(function (item) {
			var li = el('li');
			li.appendChild(el('span', searchLine(item)));
			var btn = el('button', t('Einchecken'));
			btn.type = 'button';
			btn.addEventListener('click', function () { btn.disabled = true; checkInResult(item); });
			li.appendChild(btn);
			list.appendChild(li);
		});
	}
	/** Online: GET /app/search (order number, name/e-mail as the server's Pii rules allow); offline: the package. */
	function searchTickets(q) {
		var occurrenceId = state.selected;
		q = String(q || '').trim();
		if (!q || !occurrenceId) { return Promise.resolve(); }
		var offlineSearch = function () {
			var entry = state.packages[occurrenceId];
			var names = !!(entry && entry.meta && entry.meta.rules && entry.meta.rules.attendee_names);
			renderSearch(names ? searchPackage(entry, q) : [], names ? (q.length < 3 ? t('Bitte mindestens 3 Zeichen eingeben.') : '') : t('Offline ist nur die Namenssuche möglich, und nur wenn das Prüfpaket Namen enthält.'));
		};
		if (navigator.onLine === false) { offlineSearch(); return Promise.resolve(); }
		return api('GET', 'search', null, 'q=' + encodeURIComponent(q) + '&occurrence_id=' + encodeURIComponent(occurrenceId), 5000).then(function (res) {
			if (res && true === res.ok && Array.isArray(res.results)) {
				renderSearch(res.results, res.more ? t('Weitere Treffer – bitte genauer suchen.') : '');
			} else {
				renderSearch([], res && res.message ? res.message : t('Kein Ticket gefunden.'));
			}
		}, function (err) {
			if (handleAuthError(err)) { return; }
			if (err.network || err.status >= 500) { offlineSearch(); return; }
			renderSearch([], err.message);
		});
	}
	/**
	 * Check-in of a search result: online by ticket_public_id (same rules as a scan, logged as manual
	 * check-in), offline by the package hash (decided like a scan; uploaded with ticket_hash).
	 */
	function checkInResult(item) {
		unlockAudio();
		var occurrenceId = state.selected;
		var entry = state.packages[occurrenceId];
		var key = uuid();
		var scannedAt = new Date(now()).toISOString();
		var area = (entry && entry.meta && entry.meta.device_area_id) || state.areaId || 0;
		if (item.hash) {
			state.scanHash = item.hash;
			var d = decideOffline(entry, item.hash);
			if (d.ok) {
				markAdmitted(occurrenceId, item.hash, Date.parse(scannedAt));
				showResult('ok', t('Einlass OK (offline)'), [d.type ? d.type.name : '', item.attendee_name, d.type && d.type.hint ? '⚠ ' + d.type.hint : '', t('Manuell (Suche)')].filter(Boolean).join(' · '));
				offerUndo({ key: key, occurrenceId: occurrenceId, hash: item.hash, area: area });
			} else {
				showRejected(occurrenceId, item.hash, d.result, [item.attendee_name, t('offline')], d.recent === undefined ? -1 : d.recent);
			}
			return put('log', { key: key, occurrence_id: occurrenceId, hash: item.hash, code: '', ticket_hash: d.ok ? item.hash : '', manual: true, scanned_at: scannedAt, direction: 'in', area_id: area, mode: 'offline', decision: d.result, ok: d.ok, synced: d.ok ? 0 : 1 }).then(renderLog).then(refreshCounts);
		}
		return sha256Hex('id:' + item.ticket_public_id).then(function (hash) {
			state.scanHash = '';
			return api('POST', 'scan/check-in', { ticket_public_id: item.ticket_public_id, idempotency_key: key, occurrence_id: occurrenceId, area_id: area }, null, 5000).then(function (res) {
				var kind = classifyScan(res, null);
				if ('warn' === kind) { systemWarning(res); return null; }
				var ok = 'ok' === kind;
				showResult(kind, resultText(res.result), onlineDetail(res) + ' · ' + t('Manuell (Suche)'));
				if (ok) { offerUndo({ key: key, occurrenceId: occurrenceId, hash: hash, area: area, publicId: item.ticket_public_id }); }
				return put('log', { key: key, occurrence_id: occurrenceId, hash: hash, code: '', manual: true, scanned_at: scannedAt, direction: 'in', area_id: area, mode: 'online', decision: res.result, ok: ok, synced: 1 });
			}, function (err) {
				if (handleAuthError(err)) { return null; }
				var kind = classifyScan(null, err || {});
				if ('bad' === kind) { showResult('bad', t('Fehler'), err.message); return null; }
				showResult('warn', t('Systemfehler – Ticket nicht geprüft'), t('Nur online möglich.'));
				return null;
			});
		}).then(renderLog).then(refreshCounts);
	}

	/* ---------------- check-in counter (A546) ---------------- */

	/** Counter from the verified package: admissible tickets and those used (package or this device). */
	function localCounts(entry, admitted) {
		if (!entry || !entry.index) { return null; }
		var total = 0;
		var done = 0;
		entry.index.forEach(function (tk, hash) {
			if ('revoked' === tk.s) { return; }
			total++;
			if ('checked_in' === tk.s || (admitted && admitted.has(hash))) { done++; }
		});
		return { admitted: done, total: total };
	}
	/**
	 * Values shown: the server count (all devices) plus this device's admissions that are not
	 * uploaded yet; without a server value the package count. source: 'server' or 'package'.
	 */
	function counterValues(server, local, pending) {
		if (server) { return { admitted: Math.min(server.total, server.admitted + (pending || 0)), total: server.total, source: 'server', at: server.at }; }
		return local ? { admitted: local.admitted, total: local.total, source: 'package', at: 0 } : null;
	}
	function renderCounter() {
		var box = $('counter');
		if (!box) { return; }
		var occ = state.selected;
		var v = occ ? counterValues(state.serverCounts[occ], localCounts(state.packages[occ], state.admitted[occ]), state.pendingIn[occ] || 0) : null;
		if (!v) { box.hidden = true; return; }
		box.hidden = false;
		var note = 'package' === v.source ? t('laut Prüfpaket') : (Date.now() - v.at > 90000 ? t('Stand %1', fmtTime(new Date(v.at).toISOString(), zoneOf(occ))) : '');
		box.textContent = t('Eingecheckt: %1 / %2', v.admitted, v.total) + (note ? ' · ' + note : '');
	}
	/**
	 * Fetches the server count of the selected date (GET /app/occurrences/{id}/stats) when online –
	 * at most every 10 s unless forced (date change, undo, package refresh); in between own online
	 * admissions are counted locally.
	 */
	function refreshCounts(force) {
		var occ = state.selected;
		var fresh = state.serverCounts[occ] && Date.now() - state.serverCounts[occ].at < 10000;
		if (!state.token || !occ || navigator.onLine === false || (fresh && true !== force)) { renderCounter(); return Promise.resolve(); }
		return api('GET', 'occurrences/' + encodeURIComponent(occ) + '/stats', null, null, 5000).then(function (res) {
			var total = 'number' === typeof res.admission_total ? res.admission_total : res.tickets_total;
			if ('number' === typeof res.admitted && 'number' === typeof total) { state.serverCounts[occ] = { admitted: res.admitted, total: total, at: Date.now() }; }
		}, function () { /* keep the last value */ }).then(renderCounter);
	}

	/* ---------------- undo (A547) ---------------- */

	var UNDO_MS = 30000;
	/** Reasons offered at the device (same as the web scanner); a typed reason is possible, too. */
	var UNDO_REASONS = [t('Fehlscan (falsches Ticket gescannt)'), t('Doppelt gescannt'), t('Gast hat den Einlass nicht betreten'), t('Falscher Termin oder Bereich')];
	function undoSecondsLeft(started, at) { return Math.max(0, Math.ceil((started + UNDO_MS - at) / 1000)); }

	/** Offers "Rückgängig" for 30 s after an admission of this device. */
	function offerUndo(offer) {
		state.undo = { key: offer.key, started: Date.now(), occurrenceId: offer.occurrenceId, hash: offer.hash, area: offer.area || 0, publicId: offer.publicId || '', open: false };
		var sel = $('undo-reason');
		if (sel && !sel.options.length) {
			[['', t('– Grund wählen –')]].concat(UNDO_REASONS.map(function (r) { return [r, r]; }), [['__other', t('Anderer Grund …')]]).forEach(function (entry) {
				var o = el('option', entry[1]);
				o.value = entry[0];
				sel.appendChild(o);
			});
		}
		renderUndo();
	}
	function renderUndo() {
		var box = $('undo');
		var u = state.undo;
		clearTimeout(state.undoTimer);
		if (!box) { return; }
		var left = u ? undoSecondsLeft(u.started, Date.now()) : 0;
		if (!u || (left <= 0 && !u.open)) { box.hidden = true; state.undo = null; return; }
		box.hidden = false;
		$('undo-start').hidden = u.open;
		$('undo-start').textContent = t('Rückgängig (%1 s)', left);
		$('undo-form').hidden = !u.open;
		if (!u.open) { state.undoTimer = setTimeout(renderUndo, 1000); }
	}
	function undoReason() {
		var v = $('undo-reason').value;
		return String('__other' === v ? $('undo-other').value : v || '').trim().slice(0, 255);
	}
	function unmarkAdmitted(occurrenceId, hash) {
		if (state.admitted[occurrenceId]) { state.admitted[occurrenceId].delete(hkey(hash)); }
		// The local entry counter forgets the newest admission of this hash as well.
		var map = state.local[occurrenceId];
		var rec = map && map.get(hkey(hash));
		if (rec) {
			rec.ins.pop();
			rec.inside = false;
			if (!rec.ins.length) { map.delete(hkey(hash)); }
		}
	}
	function getLog(key) { return idb('log', 'readonly', function (s) { return s.get(key); }); }

	/**
	 * Takes the offered admission back. Online admissions (and offline ones that are already uploaded)
	 * go to POST /app/scan/undo with the reason – the server checks device, area and refund state
	 * (Checkin::undo_guard()). An offline admission that is not uploaded yet was never booked: it is
	 * marked as undone in the local log and never uploaded.
	 */
	function confirmUndo(reason) {
		var u = state.undo;
		if (!u) { return Promise.resolve(); }
		$('undo-note').textContent = '';
		if (!reason) { $('undo-note').textContent = t('Bitte einen Grund wählen oder eingeben.'); return Promise.resolve(); }
		if (state.syncing) { $('undo-note').textContent = t('Synchronisierung läuft – bitte gleich erneut versuchen.'); return Promise.resolve(); }
		var done = function (entry, text) {
			unmarkAdmitted(u.occurrenceId, u.hash);
			state.undo = null;
			renderUndo();
			showResult('warn', t('Einlass zurückgenommen'), text);
			if (!entry) { return null; }
			entry.ok = false;
			entry.decision = 'undone';
			entry.undo_reason = reason;
			return put('log', entry);
		};
		return getLog(u.key).then(function (entry) {
			if (entry && !entry.synced) {
				entry.synced = 1;
				entry.code = '';
				return done(entry, t('Offline-Einlass verworfen – er wird nicht hochgeladen.'));
			}
			var publicId = u.publicId || (entry && entry.server && entry.server.ticket_public_id) || '';
			if (!publicId) { showResult('bad', t('Rückgängig nicht möglich'), t('Nur online möglich.')); return null; }
			return api('POST', 'scan/undo', { ticket_public_id: publicId, reason: reason, area_id: u.area, idempotency_key: u.key + ':undo' }, null, 8000).then(function (res) {
				if (res && true === res.ok) { return done(entry, t('Das Ticket ist wieder gültig.')); }
				showResult('bad', t('Rückgängig nicht möglich'), res && res.message ? res.message : '');
				return null;
			}, function (err) {
				if (handleAuthError(err)) { return null; }
				showResult('bad', t('Rückgängig nicht möglich'), err.network ? t('Nur online möglich.') : err.message);
				return null;
			});
		}).then(renderLog).then(function () { return refreshCounts(true); });
	}

	/* ---------------- sync ---------------- */

	function syncNow() {
		if (!state.token || state.syncing) { return Promise.resolve(); }
		state.syncing = true;
		return idb('log', 'readonly', function (s) { return s.index('synced').getAll(0); }).then(function (pending) {
			if (!pending.length) { return; }
			var batch = pending.slice(0, SYNC_BATCH);
			// A544: admissions from the offline search have no code; the server books them by the package hash.
			var scans = batch.map(function (e) { var scan = { code: e.code, idempotency_key: e.key, scanned_at: e.scanned_at, direction: e.direction, area_id: e.area_id }; if (!e.code && e.ticket_hash) { scan.ticket_hash = e.ticket_hash; } return scan; });
			return api('POST', 'scan/sync', { scans: scans }, null, 30000).then(function (res) {
				var byKey = {};
				(res.results || []).forEach(function (r) { byKey[r.idempotency_key] = r; });
				var done = 0;
				return idb('log', 'readwrite', function (s) {
					batch.forEach(function (e) {
						var r = byKey[e.key];
						// A345: a retryable server error booked nothing – the scan stays queued (code kept)
						// and is uploaded again with the next sync.
						if (!r || r.retryable) { return; }
						done++;
						e.synced = 1;
						e.code = '';
						e.server = { ok: !!r.ok, result: r.result, ticket_public_id: r.ticket_public_id || '', conflict: !!r.conflict, conflict_id: r.conflict_id || 0, double_admission: !!r.double_admission, auto_resolved: !!r.auto_resolved, offline_expired: !!r.offline_expired, message: r.message || '' };
						s.put(e);
					});
				}).then(function () { if (done && pending.length > SYNC_BATCH) { state.syncing = false; return syncNow(); } });
			}, function (err) { if (!handleAuthError(err) && !err.network) { console.warn('sync', err); } });
		}).then(function () { state.syncing = false; }, function (err) { state.syncing = false; throw err; }).then(pruneLog).then(renderLog);
	}

	function renderLog() {
		return allOf('log').then(function (entries) {
			entries.sort(function (a, b) { return a.scanned_at < b.scanned_at ? 1 : -1; });
			var pending = entries.filter(function (e) { return !e.synced; }).length;
			state.pendingCount = pending;
			$('pending').textContent = t('%d offen', pending);
			state.pendingIn = {};
			entries.forEach(function (e) { if (!e.synced && e.ok && 'in' === (e.direction || 'in')) { state.pendingIn[e.occurrence_id] = (state.pendingIn[e.occurrence_id] || 0) + 1; } });
			renderCounter();
			var log = $('log');
			log.textContent = '';
			entries.slice(0, 25).forEach(function (e) {
				var li = el('li');
				li.appendChild(el('span', fmtTime(e.scanned_at, zoneOf(e.occurrence_id)) + ' · …' + e.hash.slice(-8) + ' · ' + resultText(e.decision)));
				var tag = 'undone' === e.decision ? el('span', t('zurückgenommen'), 'tag warn') : !e.synced ? el('span', t('wartet auf Sync'), 'tag warn') : e.server ? el('span', e.server.conflict ? t('Konflikt') : t('synchronisiert'), 'tag ' + (e.server.conflict ? 'bad' : 'ok')) : el('span', 'offline' === e.mode ? t('offline') : t('online'), 'tag');
				li.appendChild(tag);
				log.appendChild(li);
			});
			var conflicts = $('conflicts');
			conflicts.textContent = '';
			var withConflict = entries.filter(function (e) { return e.server && e.server.conflict; });
			withConflict.forEach(function (e) {
				var li = el('li');
				var text = fmtTime(e.scanned_at, zoneOf(e.occurrence_id)) + ' · …' + e.hash.slice(-8) + ' · ' + resultText(e.server.result);
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
			return syncNow().then(function () { return refreshCounts(true); });
		});
	}

	function setNet() {
		var on = navigator.onLine !== false;
		var pill = $('net');
		pill.textContent = on ? t('Online') : t('Offline');
		pill.className = 'pill' + (on ? '' : ' offline');
	}

	/* ---------------- camera ---------------- */

	// run: number of the latest start (A316); starting: getUserMedia()/play() still pending.
	var camera = { stream: null, detector: null, timer: 0, canvas: null, run: 0, starting: false };
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
	function stopTracks(stream) { if (stream) { stream.getTracks().forEach(function (track) { track.stop(); }); } }
	// A316: a second tap while the camera is still starting (permission prompt, slow getUserMedia) must not
	// open a second stream that keeps running unseen: the button is locked until the start is through, and
	// a stream that arrives for an outdated start (stopped or replaced meanwhile) is stopped at once.
	function startCamera() {
		unlockAudio();
		if (camera.starting) { return; }
		if (camera.stream) { stopCamera(); return; }
		var video = $('video');
		var button = $('camera');
		var run = ++camera.run;
		var mine = null;
		camera.starting = true;
		button.disabled = true;
		navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (stream) {
			mine = stream;
			if (run !== camera.run) { stopTracks(stream); throw new Error('stale'); }
			if (camera.stream && camera.stream !== stream) { stopTracks(camera.stream); }
			camera.stream = stream;
			video.srcObject = stream;
			video.hidden = false;
			button.textContent = t('Kamera stoppen');
			setupTrack(stream);
			return video.play();
		}).then(function () {
			if ('BarcodeDetector' in window) {
				try { camera.detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { camera.detector = null; }
			}
			return camera.detector ? null : loadJsQr();
		}).then(function () {
			if (run !== camera.run) { return; }
			camera.starting = false;
			button.disabled = false;
			tick();
		}).catch(function (e) {
			if (run !== camera.run) { stopTracks(mine); return; }
			camera.starting = false;
			button.disabled = false;
			showResult('bad', t('Kamera nicht verfügbar'), e && e.message);
			stopCamera();
		});
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
	function stopCamera() {
		camera.run++;
		camera.starting = false;
		stopTracks(camera.stream);
		camera.stream = null;
		camera.track = null;
		if ($('torch')) { $('torch').hidden = true; }
		clearTimeout(camera.timer);
		$('video').hidden = true;
		$('camera').disabled = false;
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
					if (qr.api && !apiBaseOk(qr.api)) { throw new Error(t('Der Kopplungscode gehört zu einem anderen Server.')); }
					// A code of another site of the network: pair in that site's own store (A473).
					if (qr.api) { useSite(qr.api); }
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

	/**
	 * A598: unpairing clears the scan log. With unsynchronised admissions the app syncs first; if scans are
	 * still open (offline), unpairing needs the number of open scans typed in – a mistaken tap must not throw
	 * away admissions that the server has not seen yet.
	 */
	function unpair() {
		var open = state.pendingCount;
		if (!open) {
			if (window.confirm(t('Gerät entkoppeln? Nicht synchronisierte Scans gehen verloren.'))) { doUnpair(); }
			return;
		}
		syncNow().catch(function () {}).then(function () {
			open = state.pendingCount;
			if (!open) {
				if (window.confirm(t('Alle Scans sind synchronisiert. Gerät jetzt entkoppeln?'))) { doUnpair(); }
				return;
			}
			var typed = window.prompt(t('%1$d Scans sind noch nicht synchronisiert und gingen beim Entkoppeln verloren. Bitte zuerst mit Internetverbindung synchronisieren. Zum Entkoppeln trotzdem die Zahl %1$d eingeben:', open));
			if (null !== typed && String(typed).trim() === String(open)) { doUnpair(); }
		});
	}

	function doUnpair() {
		var logout = state.token ? api('POST', 'logout', {}).catch(function () {}) : Promise.resolve();
		logout.then(function () {
			return Promise.all(['kv', 'packages', 'log'].map(function (s) { return idb(s, 'readwrite', function (st) { st.clear(); }); }));
		}).then(function () { location.reload(); });
	}

	/* ---------------- translations ---------------- */

	/** Applies the current table to the static page (data-i18n*, lang, title) and live labels. */
	function applyI18n() {
		if (document.documentElement) { document.documentElement.lang = lang(); }
		applyBrand();
		document.title = t('Offline-Scanner') + ' · ' + brandName();
		// The source text is the attribute value (data-i18n="…"); a bare data-i18n flag uses the German
		// text of the element, remembered on first use so a later table translates from the source again.
		var source = function (n, attr, current) {
			var key = n.getAttribute(attr) || n.getAttribute(attr + '-src') || '';
			if (!key) { key = String(current || '').trim(); n.setAttribute(attr + '-src', key); }
			return key;
		};
		Array.prototype.forEach.call(document.querySelectorAll('[data-i18n]'), function (n) { n.textContent = t(source(n, 'data-i18n', n.textContent)); });
		['placeholder', 'title'].forEach(function (attr) {
			Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-' + attr + ']'), function (n) { n.setAttribute(attr, t(source(n, 'data-i18n-' + attr, n.getAttribute(attr)))); });
		});
		if (display.idle) { $('result-title').textContent = t('Bereit'); }
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
		I18N.apply(document);
		if (!nacl || !window.crypto || !crypto.subtle || !window.indexedDB) {
			showResult('bad', t('Browser nicht unterstützt'), t('Benötigt HTTPS, WebCrypto und IndexedDB.'));
			return;
		}
		if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js', { scope: './' }).catch(function () {}); }
		// A597: #pair=CODE from the admin's pairing QR – paired right after start (the fragment never reaches the server).
		var pairMatch = /(?:^#|&)pair=([A-Za-z0-9-]{16,64})/.exec(location.hash || '');
		var pairCode = pairMatch ? pairMatch[1] : '';
		if (pairCode) { history.replaceState(null, '', location.pathname + location.search); }
		var param = new URLSearchParams(location.search).get('api');
		// Site (A473): ?api= of the admin link, else the site used last, else the pairing of an older
		// version. Every site has its own store, so a crafted ?api= can never redirect the token or the
		// scans of another site's pairing (audit FE-3); apiBaseOk() keeps it on this origin's app API.
		param = param && apiBaseOk(param) ? param : '';
		var current = '';
		try { current = localStorage.getItem(CURRENT_API_KEY) || ''; } catch (e) { current = ''; }
		legacyApiBase().then(function (legacy) {
			state.legacyApi = legacy;
			var apiBase = param || (apiBaseOk(current) ? current : '') || legacy || deriveApi();
			if (!apiBaseOk(apiBase)) { apiBase = deriveApi(); }
			useSite(apiBase);
			return Promise.all([kvGet('api'), kvGet('token'), kvGet('device_id'), kvGet('keys'), kvGet('clock_offset'), allOf('packages'), allOf('log'), kvGet('i18n'), kvGet('qr_keys'), kvGet('server_time'), kvGet('hw')]);
		}).then(function (v) {
			// Stored translations first, so everything below (incl. package errors) is translated offline.
			if (validI18n(v[7])) { state.i18n = v[7]; }
			applyI18n();
			state.token = v[1] || '';
			state.deviceId = v[2] || '';
			state.keys = v[3] || {};
			state.qrKeys = v[8] || {};
			state.clockOffset = v[4] || 0;
			state.serverTime = Number(v[9]) || 0;
			state.hw = v[10] && typeof v[10] === 'object' ? v[10] : {};
			if (v[0] !== state.api) { kvSet('api', state.api); }
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
				} else if (pairCode && $('pair-code')) {
					$('pair-code').value = pairCode;
					var form = $('pair-form');
					if (form.requestSubmit) { form.requestSubmit(); } else { form.dispatchEvent(new Event('submit', { cancelable: true })); }
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
				showResult('ok', t('Gekoppelt'), t('Freigegebene Events werden geladen. Fehlt ein Event, im Admin unter „Offline-Scanner“ zuweisen.'));
				return refreshAll(true);
			}).catch(function (err) {
				showResult('bad', t('Kopplung fehlgeschlagen'), err && err.message);
				$('main-view').hidden = true;
			}).then(function () { btn.disabled = false; });
		});
		$('occurrence').addEventListener('change', function (ev) { state.selected = Number(ev.target.value) || 0; renderPackage(); refreshCounts(true); });
		$('area').addEventListener('change', function (ev) { state.areaId = Number(ev.target.value) || 0; });
		$('refresh').addEventListener('click', function () { refreshAll(true); });
		$('sync').addEventListener('click', function () { syncNow(); });
		$('camera').addEventListener('click', startCamera);
		if ($('torch')) { $('torch').addEventListener('click', toggleTorch); }
		$('unpair').addEventListener('click', unpair);
		if ($('sound')) { $('sound').addEventListener('click', toggleSound); }
		var on = function (id, type, fn) { var n = $(id); if (n) { n.addEventListener(type, fn); } };
		on('overlay', 'click', hideOverlay);
		document.addEventListener('keydown', hideOverlay);
		on('search', 'submit', function (ev) { ev.preventDefault(); searchTickets($('search-q').value); });
		on('undo-start', 'click', function () { if (state.undo && undoSecondsLeft(state.undo.started, Date.now()) > 0) { state.undo.open = true; renderUndo(); $('undo-reason').focus(); } });
		on('undo-reason', 'change', function (ev) { $('undo-other').hidden = '__other' !== ev.target.value; });
		on('undo-confirm', 'click', function () { confirmUndo(undoReason()); });
		on('undo-cancel', 'click', function () { state.undo = null; renderUndo(); });
		renderSound();
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
	window.wpcapOffline = { apiBaseOk: apiBaseOk, validApiBase: validApiBase, pair: pair, storeName: function () { return dbName; }, normalizeCode: normalizeCode, scanCode: scanCode, signedCode: signedCode, classifyScan: classifyScan, handleScan: handleScan, lastScan: function () { return lastScan; }, serviceDay: serviceDay, beep: beep, showIdle: showIdle, toggleTorch: toggleTorch, setupTrack: setupTrack, refreshPackage: refreshPackage, t: t, fmtTime: fmtTime, verifySignedQr: verifySignedQr, decideSigned: decideSigned, verifyDoc: verifyDoc, buildIndex: buildIndex, loadPackage: loadPackage, packageStatus: packageStatus, decideOffline: decideOffline, validI18n: validI18n, applyI18n: applyI18n, state: publicState, syncNow: syncNow, refreshAll: refreshAll, pruneExpired: pruneExpired, pruneLog: pruneLog, qrWindow: qrWindow, startCamera: startCamera, stopCamera: stopCamera, camera: camera, undoSecondsLeft: undoSecondsLeft, offerUndo: offerUndo, confirmUndo: confirmUndo, localCounts: localCounts, counterValues: counterValues, refreshCounts: refreshCounts, searchPackage: searchPackage, searchTickets: searchTickets, checkInResult: checkInResult, holdTimes: holdTimes, shortCode: shortCode, showResult: showResult };

	boot();
}());
