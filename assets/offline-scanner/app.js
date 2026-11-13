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
	var nacl = window.nacl;
	var I18N = window.WPCAP_I18N || { t: function (x) { return x; }, locale: 'de-DE', apply: function () {} };
	var t = I18N.t;

	var state = {
		api: '', token: '', deviceId: '', keys: {}, clockOffset: 0,
		occurrences: [], selected: 0, areaId: 0,
		packages: {}, // occurrence_id -> { record, index: Map, meta, error }
		admitted: {}, // occurrence_id -> Set of hashes admitted on this device
		busy: false, lastRefresh: 0,
		forceFull: {} // occurrence_id -> true after a broken delta chain
	};

	function $(id) { return document.getElementById(id); }
	function now() { return Date.now() + state.clockOffset; }

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
	function fmtTime(iso) {
		var d = new Date(iso);
		return isNaN(d) ? '—' : d.toLocaleString(I18N.locale, { dateStyle: 'short', timeStyle: 'short' });
	}
	function el(tag, text, cls) {
		var e = document.createElement(tag);
		if (text !== undefined && text !== null) { e.textContent = String(text); }
		if (cls) { e.className = cls; }
		return e;
	}

	var RESULT_TEXT = {
		checked_in: 'Einlass OK', already_checked_in: 'Bereits verwendet', revoked: 'Widerrufen', unknown: 'Unbekanntes Ticket',
		wrong_event: 'Falsches Event', wrong_occurrence: 'Falscher Termin', wrong_area: 'Falscher Einlassbereich',
		cancelled_event: 'Termin abgesagt', out_of_window: 'Außerhalb des Einlasszeitraums', not_valid_today: 'Heute nicht gültig',
		daily_limit_reached: 'Tageskontingent erreicht', entry_limit_reached: 'Einlasslimit erreicht', outside_entry_window: 'Außerhalb des Einlassfensters',
		already_inside: 'Bereits drinnen', area_full: 'Bereich voll', forbidden: 'Kein Einlasszugriff', package_expired: 'Prüfpaket abgelaufen', no_package: 'Kein gültiges Prüfpaket',
		invalid_code: 'Kein Ticketcode erkannt', rate_limited: 'Zu viele Hilfscode-Eingaben'
	};
	function resultText(code) { return RESULT_TEXT[code] ? t(RESULT_TEXT[code]) : code; }

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

	function showResult(kind, title, detail) {
		var box = $('result');
		box.className = 'result ' + kind;
		$('result-title').textContent = title;
		$('result-detail').textContent = detail || '';
		if ('ok' === kind) { tone([660, 990], 0.12); } else if ('bad' === kind) { tone([196, 147], 0.32, 'square'); } else if ('warn' === kind) { tone([440, 440], 0.15, 'triangle'); }
		try { if (navigator.vibrate) { navigator.vibrate('ok' === kind ? 80 : ('warn' === kind ? [120, 80, 120] : [250, 100, 250, 100, 250])); } } catch (e) { /* no vibration */ }
	}
	/** "Erstmals eingelassen" / "Bereits eingelassen um 19:42" from the server's admission history. */
	function historyText(res) {
		if (!res) { return ''; }
		if (res.ok) {
			if (res.first_admission) { return t('Erstmals eingelassen'); }
			return res.admissions > 1 && res.first_checked_in_at ? t('Einlass Nr. %1 – zuerst eingelassen um %2', res.admissions, fmtTime(res.first_checked_in_at)) : '';
		}
		if (!res.first_checked_in_at) { return ''; }
		return t('Bereits eingelassen um %1', fmtTime(res.first_checked_in_at)) + (res.last_checked_in_area ? ' · ' + res.last_checked_in_area : '');
	}
	function onlineDetail(res) {
		return [res.ticket, res.attendee_name, historyText(res), res.staff_hint ? '⚠ ' + res.staff_hint : '', res.help_code ? '⚠ ' + t('Per Hilfscode geprüft') : '', 'online'].filter(Boolean).join(' · ');
	}

	/* ---------------- API ---------------- */

	function deriveApi() {
		var path = location.pathname;
		var i = path.indexOf('/wp-content/');
		return location.origin + (i >= 0 ? path.slice(0, i) : '') + '/wp-json/pass-captain/v1/app/';
	}
	/** Only same-origin API bases are accepted (the token must never go to another host). */
	function sameOrigin(url) {
		try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
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

	function api(method, route, body, query, timeoutMs) {
		var ctrl = window.AbortController ? new AbortController() : null;
		var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, timeoutMs || 15000) : null;
		var headers = { 'Accept': 'application/json' };
		if (state.token) { headers.Authorization = 'Bearer ' + state.token; }
		if (body) { headers['Content-Type'] = 'application/json'; }
		return fetch(endpoint(route, query), { method: method, headers: headers, body: body ? JSON.stringify(body) : undefined, credentials: 'omit', cache: 'no-store', signal: ctrl ? ctrl.signal : undefined })
			.then(function (res) {
				if (timer) { clearTimeout(timer); }
				return res.json().catch(function () { return null; }).then(function (data) {
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

	function handleAuthError(err) {
		if (err && 401 === err.status) {
			showResult('bad', t('Gerät nicht mehr gekoppelt'), t('Token abgelaufen oder widerrufen. Bitte neu koppeln.'));
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
		if ('wpcap-offline-package' !== payload.format || 1 !== payload.v) { throw new Error(t('Unbekanntes Paketformat')); }
		if (payload.key_id !== doc.key_id) { throw new Error(t('Schlüssel-ID passt nicht')); }
		if (payload.device_id !== state.deviceId) { throw new Error(t('Paket gehört zu einem anderen Gerät')); }
		return payload;
	}

	/** Rebuilds the ticket index from a stored record (full + chained deltas), verifying every document. */
	function buildIndex(record) {
		var full = verifyDoc(record.full);
		if ('full' !== full.kind || full.occurrence.id !== record.occurrence_id) { throw new Error(t('Paket passt nicht zum Termin')); }
		var index = new Map();
		full.tickets.forEach(function (t) { index.set(t.h, t); });
		var meta = full;
		var cursor = full.cursor;
		(record.deltas || []).forEach(function (doc) {
			var d = verifyDoc(doc);
			if ('delta' !== d.kind || d.since !== cursor || d.occurrence.id !== record.occurrence_id) { throw new Error(t('Delta-Kette unterbrochen')); }
			d.tickets.forEach(function (t) { index.set(t.h, t); });
			cursor = d.cursor;
			meta = d;
		});
		return { index: index, meta: meta, cursor: cursor };
	}

	function loadPackage(record) {
		var entry = { record: record, index: null, meta: null, error: '' };
		try {
			var built = buildIndex(record);
			entry.index = built.index;
			entry.meta = built.meta;
		} catch (e) {
			entry.error = e.message;
		}
		state.packages[record.occurrence_id] = entry;
		return entry;
	}

	/** Fetches and stores the package of an occurrence (delta when possible). */
	function refreshPackage(occurrenceId) {
		var current = state.packages[occurrenceId];
		var usable = current && current.index && current.record.deltas.length < MAX_DELTAS && !state.forceFull[occurrenceId];
		var query = 'occurrence_id=' + encodeURIComponent(occurrenceId) + (usable ? '&since=' + encodeURIComponent(current.meta.cursor) : '');
		return api('GET', 'offline/package', null, query).then(function (doc) {
			var payload = verifyDoc(doc);
			var record;
			if ('full' === payload.kind) {
				record = { occurrence_id: occurrenceId, full: doc, deltas: [] };
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
		if (entry.error) { return { ok: false, code: 'no_package', text: t('Prüfpaket ungültig: %1', entry.error) }; }
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
				t('%1 Tickets im Paket · signiert (Ed25519, Schlüssel %2) · Stand %3', valid, m.key_id, fmtTime(m.issued_at)),
				t('Gültig bis %1 · Doppelscans: %2', fmtTime(m.expires_at), 'first_sync_wins' === m.rules.duplicate_policy ? t('erste Synchronisierung gewinnt') : t('zur Prüfung'))
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

	/** Offline decision against the verified package (mirrors the server rules that can be checked locally). */
	function decideOffline(entry, hash) {
		var status = packageStatus(entry);
		if (!status.ok) { return { ok: false, result: status.code }; }
		var t = entry.index.get(hash);
		if (!t) { return { ok: false, result: 'unknown' }; }
		if ('revoked' === t.s) { return { ok: false, result: 'revoked' }; }
		var type = null;
		entry.meta.ticket_types.forEach(function (tt) { if (tt.id === t.t) { type = tt; } });
		// Doors-open time of the occurrence: nobody is admitted before it.
		var doors = entry.meta.occurrence && entry.meta.occurrence.doors_open_at ? Date.parse(entry.meta.occurrence.doors_open_at) : NaN;
		if (!isNaN(doors) && now() < doors) { return { ok: false, result: 'outside_entry_window', ticket: t, type: type }; }
		var multi = !!(type && type.multi);
		var admitted = state.admitted[entry.record.occurrence_id];
		if (!multi && ('checked_in' === t.s || (admitted && admitted.has(hash)))) { return { ok: false, result: 'already_checked_in', ticket: t, type: type }; }
		var area = entry.meta.device_area_id || state.areaId || 0;
		if (type && type.areas.length && type.areas.indexOf(area) < 0) { return { ok: false, result: 'wrong_area', ticket: t, type: type }; }
		return { ok: true, result: 'checked_in', ticket: t, type: type, multi: multi };
	}

	function markAdmitted(occurrenceId, hash) {
		if (!state.admitted[occurrenceId]) { state.admitted[occurrenceId] = new Set(); }
		state.admitted[occurrenceId].add(hash);
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
		var parsed = scanCode(raw);
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
		if (code === lastScan.code && Date.now() - lastScan.at < 3000) { lastScan.at = Date.now(); return Promise.resolve(); }
		lastScan = { code: code, at: Date.now() };
		var occurrenceId = state.selected;
		var entry = state.packages[occurrenceId];
		if (!occurrenceId) { showResult('bad', t('Kein Termin gewählt')); return Promise.resolve(); }
		var key = uuid();
		var scannedAt = new Date(now()).toISOString();
		var area = (entry && entry.meta && entry.meta.device_area_id) || state.areaId || 0;
		return sha256Hex(code).then(function (hash) {
			var online = navigator.onLine !== false;
			var attempt = online
				? api('POST', 'scan/check-in', { code: code, idempotency_key: key, occurrence_id: occurrenceId, area_id: area }, null, 5000)
				: Promise.reject({ network: true });
			return attempt.then(function (res) {
				var kind = classifyScan(res, null);
				if ('warn' === kind) { systemWarning(res); return; }
				var ok = 'ok' === kind;
				if (ok) { markAdmitted(occurrenceId, hash); }
				showResult(kind, resultText(res.result), onlineDetail(res));
				return put('log', { key: key, occurrence_id: occurrenceId, hash: hash, code: '', scanned_at: scannedAt, direction: 'in', area_id: area, mode: 'online', decision: res.result, ok: ok, synced: 1 });
			}, function (err) {
				// Network errors, timeouts and server outages fall back to the offline decision; only a
				// wpcap_* error of the plugin is a refusal, anything else a system warning (A315).
				var kind = classifyScan(null, err || {});
				if ('offline' !== kind) {
					if (handleAuthError(err)) { return; }
					if ('rate' === kind) { showResult('warn', resultText('rate_limited'), err.message); return; }
					if ('bad' === kind) { showResult('bad', t('Fehler'), err.message); return; }
					systemWarning(err);
					return;
				}
				var d = decideOffline(entry, hash);
				var typeName = d.type ? d.type.name : '';
				var hint = d.type && d.type.hint ? '⚠ ' + d.type.hint : '';
				var name = d.ticket && d.ticket.n ? d.ticket.n : '';
				if (d.ok) {
					markAdmitted(occurrenceId, hash);
					showResult('ok', t('Einlass OK (offline)'), [typeName, name, hint, d.multi ? t('Mehrfachticket – Prüfung beim Sync') : ''].filter(Boolean).join(' · '));
				} else {
					showResult('bad', resultText(d.result), [typeName, name, t('offline')].filter(Boolean).join(' · '));
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
						if (!r) { return; }
						e.synced = 1;
						e.code = '';
						e.server = { ok: !!r.ok, result: r.result, conflict: !!r.conflict, conflict_id: r.conflict_id || 0, double_admission: !!r.double_admission, auto_resolved: !!r.auto_resolved, offline_expired: !!r.offline_expired, message: r.message || '' };
						s.put(e);
					});
				}).then(function () { if (pending.length > SYNC_BATCH) { return syncNow(); } });
			}, function (err) { if (!handleAuthError(err) && !err.network) { console.warn('sync', err); } });
		}).then(renderLog);
	}

	function renderLog() {
		return allOf('log').then(function (entries) {
			entries.sort(function (a, b) { return a.scanned_at < b.scanned_at ? 1 : -1; });
			var pending = entries.filter(function (e) { return !e.synced; }).length;
			$('pending').textContent = t('%1 offen', pending);
			var log = $('log');
			log.textContent = '';
			entries.slice(0, 25).forEach(function (e) {
				var li = el('li');
				li.appendChild(el('span', fmtTime(e.scanned_at) + ' · …' + e.hash.slice(-8) + ' · ' + resultText(e.decision)));
				var tag = !e.synced ? el('span', t('wartet auf Sync'), 'tag warn') : e.server ? el('span', e.server.conflict ? t('Konflikt') : t('synchronisiert'), 'tag ' + (e.server.conflict ? 'bad' : 'ok')) : el('span', e.mode, 'tag');
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
				li.appendChild(el('span', e.server.auto_resolved ? t('automatisch geklärt') : t('zur Prüfung (#%1)', e.server.conflict_id), 'tag ' + (e.server.auto_resolved ? 'ok' : 'bad')));
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
			if (res.server_time) { state.clockOffset = Date.parse(res.server_time) - Date.now(); kvSet('clock_offset', state.clockOffset); }
			return setKeys(res.keys);
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
				return chain.then(function () { return refreshPackage(id).catch(function (e) { console.warn('package', id, e); }); });
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
		unlockAudio();
		if (camera.stream) { stopCamera(); return; }
		var video = $('video');
		navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (stream) {
			camera.stream = stream;
			video.srcObject = stream;
			video.hidden = false;
			$('camera').textContent = t('Kamera stoppen');
			return video.play();
		}).then(function () {
			if ('BarcodeDetector' in window) {
				try { camera.detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { camera.detector = null; }
			}
			return camera.detector ? null : loadJsQr();
		}).then(tick).catch(function (e) { showResult('bad', t('Kamera nicht verfügbar'), e && e.message); stopCamera(); });
	}
	function stopCamera() {
		if (camera.stream) { camera.stream.getTracks().forEach(function (t) { t.stop(); }); }
		camera.stream = null;
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
					if (qr.api && !sameOrigin(qr.api)) { throw new Error(t('Der Kopplungscode gehört zu einem anderen Server.')); }
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
				setKeys(res.offline && res.offline.keys)
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
		var param = new URLSearchParams(location.search).get('api');
		Promise.all([kvGet('api'), kvGet('token'), kvGet('device_id'), kvGet('keys'), kvGet('clock_offset'), allOf('packages'), allOf('log')]).then(function (v) {
			var stored = v[0];
			var api = param && sameOrigin(param) ? param : (stored || deriveApi());
			if (!sameOrigin(api)) { api = deriveApi(); }
			state.api = api;
			state.token = v[1] || '';
			state.deviceId = v[2] || '';
			state.keys = v[3] || {};
			state.clockOffset = v[4] || 0;
			if (param && sameOrigin(param) && param !== stored) { kvSet('api', param); }
			v[5].forEach(loadPackage);
			v[6].forEach(function (e) { if (e.ok) { markAdmitted(e.occurrence_id, e.hash); } });
			if (param) { history.replaceState(null, '', location.pathname); }
			showView();
			setNet();
			if (state.token) {
				renderOccurrences();
				renderLog();
				refreshAll(true);
			}
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
		$('unpair').addEventListener('click', unpair);
		$('sound').addEventListener('click', toggleSound);
		renderSound();
		$('manual').addEventListener('submit', function (ev) {
			ev.preventDefault();
			lastScan = { code: '', at: 0 };
			handleScan($('manual-code').value).then(function () { $('manual-code').value = ''; });
		});
		window.addEventListener('online', function () { setNet(); refreshAll(true); });
		window.addEventListener('offline', setNet);
		setInterval(function () { if (navigator.onLine !== false) { refreshAll(false); } renderPackage(); }, 30000);
	}

	// Exposed for diagnostics/tests only (no secrets in here).
	window.wpcapOffline = { normalizeCode: normalizeCode, scanCode: scanCode, classifyScan: classifyScan, handleScan: handleScan, lastScan: function () { return lastScan; }, verifyDoc: verifyDoc, decideOffline: decideOffline, state: state, syncNow: syncNow, refreshAll: refreshAll };

	boot();
}());
