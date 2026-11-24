(function () {
	'use strict';
	/*
	 * Pure decision helpers (no DOM), exposed as window.wpcapScannerLogic for the JS tests.
	 *
	 * scanCode(): same rule as PassCaptain\Checkin::normalize_code() – exactly one 64-hex ticket code.
	 * A hex run longer than 64 characters or two different codes in one input (a keyboard wedge scanner
	 * typing into a field that still holds the previous code) are "ambiguous" and never submitted.
	 */
	function scanCode(value) {
		const runs = String(value || '').match(/[a-f0-9]{64,}/gi) || [];
		const codes = runs.map(function (run) { return run.toLowerCase(); }).filter(function (run, i, all) { return all.indexOf(run) === i; });
		if (!codes.length) return { code: '', ambiguous: false };
		if (codes.length > 1 || codes[0].length !== 64) return { code: '', ambiguous: true };
		return { code: codes[0], ambiguous: false };
	}
	/*
	 * Classifies a server answer (A315). Only an error the plugin itself produced (JSON with a wpcap_*
	 * code) is a verdict on the ticket ("refusal"); a WAF/proxy/login page, an unknown error code, an
	 * answer that is no JSON object or a 2xx answer without status checked_in/checked_out is a system
	 * problem ("system"), never "no entry" and never a green admission.
	 * data: the parsed JSON object, or null when the body was no JSON object.
	 */
	function classifyAnswer(status, data) {
		const code = data && typeof data.code === 'string' ? data.code : '';
		if (status >= 200 && status < 300) return data && (data.status === 'checked_in' || data.status === 'checked_out') ? 'success' : 'system';
		if (code === 'rest_cookie_invalid_nonce') return 'session';
		if (status === 429) return 'rate';
		if (status >= 500 || !data || code.indexOf('wpcap_') !== 0 || /retry|busy/.test(code) || data.data && data.data.retryable) return 'system';
		return 'refusal';
	}
	/* Latest queued (not yet uploaded) admission of a code on this device, or null (A542). */
	function queuedAdmission(items, code) {
		let found = null;
		(items || []).forEach(function (item) {
			if (!item || scanCode(item.token).code !== code) return;
			if (!found || String(item.scanned_at || '') >= String(found.scanned_at || '')) found = item;
		});
		return found && (found.direction || 'in') === 'in' ? found : null;
	}
	const REQUEST_TIMEOUT_MS = 5000;
	/* A547: "Rückgängig" is offered for UNDO_MS after an admission; whole seconds left (0 = gone). */
	const UNDO_MS = 30000;
	function undoSecondsLeft(startedAt, now) {
		return Math.max(0, Math.ceil((startedAt + UNDO_MS - now) / 1000));
	}
	/* Reason of an undo: the chosen preset, or the typed text for "other"; '' when none. */
	function undoReason(choice, other) {
		const value = choice === '__other' ? String(other || '') : String(choice || '');
		return value.trim().slice(0, 255);
	}
	/*
	 * A546: counter line "Eingecheckt: 120 / 300 · +3 offline · Stand 19:42". i18n: counter,
	 * counterQueued, counterStale; stale: time label of the last server value when it is not fresh.
	 */
	function counterText(i18n, admitted, total, queued, stale) {
		const strings = i18n || {};
		let text = (strings.counter || '%1$d / %2$d').replace('%1$d', String(admitted)).replace('%2$d', String(total));
		if (queued > 0) text += ' · ' + (strings.counterQueued || '+%d').replace('%d', String(queued));
		if (stale) text += ' · ' + (strings.counterStale || '%s').replace('%s', stale);
		return text;
	}
	window.wpcapScannerLogic = { scanCode: scanCode, classifyAnswer: classifyAnswer, queuedAdmission: queuedAdmission, undoSecondsLeft: undoSecondsLeft, undoReason: undoReason, counterText: counterText, REQUEST_TIMEOUT_MS: REQUEST_TIMEOUT_MS, UNDO_MS: UNDO_MS };

	const root = document.querySelector('.wpcap-scanner');
	if (!root || !window.WPCAP_SCANNER) return;
	const form = root.querySelector('.wpcap-scan-form');
	const input = form.querySelector('[name="token"]');
	const result = root.querySelector('.wpcap-scan-result');
	const live = root.querySelector('.wpcap-scan-live');
	const modeBanner = root.querySelector('.wpcap-scan-mode');
	const video = root.querySelector('.wpcap-camera');
	// Offline queue per site and WordPress user (keyed hashes from the server, never the raw IDs).
	// Site (A472): the sites of a subdirectory network (example.org/a/, example.org/b/) share one
	// origin and so one localStorage; without the site in the key a scan queued on site A was
	// uploaded to site B (unknown ticket there) and lost. User: a shared device must not hand one
	// user's queued ticket codes to the next – queues of other users (any site) and the old shared
	// queues are removed as soon as a different user opens the scanner (the browser cannot be cleared
	// on logout itself). Queues of the same user on other sites stay until that site's scanner
	// uploads them.
	const privacy = window.WPCAP_SCANNER_PRIVACY || {};
	const owner = String(privacy.owner || 'anonymous').replace(/[^A-Za-z0-9_-]/g, '');
	const site = String(privacy.site || 'default').replace(/[^A-Za-z0-9_-]/g, '') || 'default';
	const queuePrefix = 'wpcap-offline-scans-v3-';
	const queueKey = queuePrefix + site + '.' + owner;
	const legacyPrefix = 'wpcap-offline-scans-v2-';
	const ownerKey = 'wpcap-scanner-owner';
	(function isolateQueue() {
		try {
			const ls = window.localStorage;
			const previousOwner = ls.getItem(ownerKey);
			// Queues from before the site key: v2 (per user) is adopted by this user's first scanner
			// start after the update (the one site it could belong to before), v1 (shared) only on a
			// browser that never had an owner. Expired items are dropped by the age check below.
			const legacy = ls.getItem(legacyPrefix + owner) || (!previousOwner ? ls.getItem('wpcap-offline-scans-v1') : null);
			if (legacy && !ls.getItem(queueKey)) ls.setItem(queueKey, legacy);
			const foreign = [];
			for (let i = 0; i < ls.length; i++) {
				const key = ls.key(i);
				if (!key) continue;
				const ours = key.indexOf(queuePrefix) === 0 && key.slice(key.lastIndexOf('.') + 1) === owner;
				if (key === 'wpcap-offline-scans-v1' || key.indexOf(legacyPrefix) === 0 || (key.indexOf(queuePrefix) === 0 && !ours)) foreign.push(key);
			}
			foreign.forEach(function (key) { ls.removeItem(key); });
			ls.setItem(ownerKey, owner);
		} catch (ignore) {}
	}());
	const t = window.WPCAP_SCANNER.i18n || {};
	// Result banner: icon + large text, colour is never the only signal. Screen readers get a short
	// separate announcement (the banner itself holds buttons and is not a live region), staff without
	// sight of the screen a tone and a vibration. A full-screen overlay (like the offline app) repeats
	// the verdict in large type for the door; it never takes focus, so keyboard wedge scanners keep
	// typing into the code field.
	const ICONS = { success: '✓', error: '✕', warning: '!', busy: '…' };
	const overlay = root.querySelector('.wpcap-scan-overlay');
	const soundButton = root.querySelector('.wpcap-sound-toggle');
	const soundKey = 'wpcap-scanner-sound-v1';
	let soundOn = true;
	try { soundOn = window.localStorage.getItem(soundKey) !== 'off'; } catch (ignore) { soundOn = true; }
	let audio = null;
	function unlockAudio() {
		// One AudioContext, created in a user gesture (camera start / submit): iOS keeps it silent otherwise.
		if (!soundOn || audio || !(window.AudioContext || window.webkitAudioContext)) return;
		try { audio = new (window.AudioContext || window.webkitAudioContext)(); } catch (ignore) { audio = null; }
	}
	// Distinct patterns: success = short rising two-tone, error = long low buzz, warning = two equal beeps.
	function tone(frequencies, length, type) {
		if (!audio || !soundOn) return;
		const duration = length || 0.18;
		try {
			if (audio.state === 'suspended') audio.resume();
			let at = audio.currentTime;
			frequencies.forEach(function (frequency) {
				const osc = audio.createOscillator();
				const gain = audio.createGain();
				osc.type = type || 'sine';
				osc.frequency.value = frequency;
				gain.gain.setValueAtTime(0.2, at);
				gain.gain.exponentialRampToValueAtTime(0.001, at + duration);
				osc.connect(gain).connect(audio.destination);
				osc.start(at);
				osc.stop(at + duration + 0.02);
				at += duration + 0.04;
			});
		} catch (ignore) {}
	}
	function vibrate(pattern) {
		try { if (navigator.vibrate) navigator.vibrate(pattern); } catch (ignore) {}
	}
	function feedback(state) {
		if (state === 'success') { tone([660, 990], 0.12); vibrate(80); }
		else if (state === 'error') { tone([196, 147], 0.32, 'square'); vibrate([250, 100, 250, 100, 250]); }
		else if (state === 'warning') { tone([440, 440], 0.15, 'triangle'); vibrate([120, 80, 120]); }
	}
	// Tone and vibration of a result state (alias for the tests of the earlier feedback helper).
	const beep = feedback;
	// Sound on/off per browser (vibration stays on: it is silent).
	function renderSound() {
		if (!soundButton) return;
		soundButton.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
		soundButton.textContent = soundOn ? (t.soundOn || 'Sound on') : (t.soundOff || 'Sound off');
	}
	if (soundButton) {
		soundButton.addEventListener('click', function () {
			soundOn = !soundOn;
			try { window.localStorage.setItem(soundKey, soundOn ? 'on' : 'off'); } catch (ignore) {}
			if (soundOn) { unlockAudio(); tone([660], 0.1); }
			renderSound();
		});
		renderSound();
	}
	// Local date/time of a server timestamp: time only for today, else date and time.
	const locale = document.documentElement.lang || undefined;
	// A516: with the occurrence's time zone (ticket answer "timezone") the time is shown there, with the
	// zone abbreviation when it differs from the device zone; "today" is judged in that zone too.
	function when(iso, zone) {
		const date = new Date(iso);
		if (!iso || isNaN(date.getTime())) return '';
		const tz = zone ? { timeZone: zone } : {};
		try {
			const day = function (d) { return new Intl.DateTimeFormat('en-CA', Object.assign({ year: 'numeric', month: '2-digit', day: '2-digit' }, tz)).format(d); };
			let text = day(date) === day(new Date())
				? date.toLocaleTimeString(locale, Object.assign({ hour: '2-digit', minute: '2-digit' }, tz))
				: date.toLocaleString(locale, Object.assign({ dateStyle: 'short', timeStyle: 'short' }, tz));
			if (zone) {
				const offset = function (z) { return new Intl.DateTimeFormat('en-US', Object.assign({ timeZoneName: 'longOffset' }, z ? { timeZone: z } : {})).format(date).split(' ').pop(); };
				if (offset(zone) !== offset('')) {
					const part = new Intl.DateTimeFormat(locale, { timeZone: zone, timeZoneName: 'short' }).formatToParts(date).filter(function (p) { return p.type === 'timeZoneName'; })[0];
					text += ' ' + (part ? part.value : zone);
				}
			}
			return text;
		} catch (ignore) { return date.toLocaleString(); }
	}
	// Lines about earlier admissions ("Erstmals eingelassen", "Bereits eingelassen um 19:42 · Bereich: Nord").
	function historyLines(data, admitted) {
		const lines = [];
		if (!data) return lines;
		if (admitted) {
			if (data.first_admission) lines.push(t.firstAdmission || 'First admission');
			else if (data.admissions > 1 && data.first_checked_in_at) lines.push((t.reentry || '%1$s %2$s').replace('%1$s', String(data.admissions)).replace('%2$s', when(data.first_checked_in_at, data.timezone)));
		} else if (data.first_checked_in_at) {
			let line = (t.alreadyAt || '%s').replace('%s', when(data.first_checked_in_at, data.timezone));
			if (data.last_checked_in_at && data.admissions > 1) line += ', ' + (t.lastAt || '%s').replace('%s', when(data.last_checked_in_at, data.timezone));
			if (data.first_checked_in_by) line += ' ' + (t.byWhom || '(%s)').replace('%s', data.first_checked_in_by); // A595: device or person
			// Staff member behind the device of the first admission, when the device label was shown.
			if (data.first_checked_in_staff && data.first_checked_in_staff !== data.first_checked_in_by) line += ' · ' + (t.staffName || '%s').replace('%s', data.first_checked_in_staff);
			// Entrance of the first admission; the area of the latest one only when it differs.
			if (data.first_checked_in_area) line += ' · ' + (t.atEntrance || '%s').replace('%s', data.first_checked_in_area);
			if (data.last_checked_in_area && data.last_checked_in_area !== data.first_checked_in_area) line += ' · ' + (t.atArea || '%s').replace('%s', data.last_checked_in_area);
			lines.push(line);
		}
		return lines;
	}
	let overlayTimer = 0;
	function hideOverlay() {
		if (!overlay) return;
		overlay.hidden = true;
		window.clearTimeout(overlayTimer);
	}
	function showOverlay(state, title, lines) {
		if (!overlay) return;
		overlay.textContent = '';
		overlay.dataset.state = state;
		const icon = document.createElement('div');
		icon.className = 'wpcap-overlay-icon';
		icon.textContent = ICONS[state] || '';
		const heading = document.createElement('div');
		heading.className = 'wpcap-overlay-title';
		heading.textContent = title;
		overlay.appendChild(icon);
		overlay.appendChild(heading);
		lines.filter(Boolean).forEach(function (line) {
			const row = document.createElement('div');
			row.className = 'wpcap-overlay-line' + (line.indexOf('⚠') === 0 ? ' wpcap-overlay-hint' : '');
			row.textContent = line;
			overlay.appendChild(row);
		});
		if (t.dismiss) {
			const close = document.createElement('div');
			close.className = 'wpcap-overlay-close';
			close.textContent = t.dismiss;
			overlay.appendChild(close);
		}
		overlay.hidden = false;
		window.clearTimeout(overlayTimer);
		// Admissions clear quickly for the next guest; refusals stay until tapped (max. 8 s).
		overlayTimer = window.setTimeout(hideOverlay, state === 'success' ? 1800 : (state === 'warning' ? 4000 : 8000));
	}
	if (overlay) {
		overlay.addEventListener('click', function () { hideOverlay(); input.focus(); });
		document.addEventListener('keydown', function () { if (!overlay.hidden) hideOverlay(); });
	}
	// Current undo offer (A547); a new result replaces it.
	let undoOffer = null;
	// show(state, text[, details]): details = { title, lines } for the full-screen overlay.
	function show(state, text, details) {
		undoOffer = null;
		result.textContent = '';
		result.dataset.state = state;
		const icon = document.createElement('span');
		icon.className = 'wpcap-scan-icon';
		icon.setAttribute('aria-hidden', 'true');
		icon.textContent = ICONS[state] || '';
		const message = document.createElement('span');
		message.className = 'wpcap-scan-text';
		message.textContent = text;
		result.appendChild(icon);
		result.appendChild(message);
		if (state === 'busy') { hideOverlay(); return; }
		const prefix = state === 'success' ? (t.ok || 'OK') : (state === 'error' ? (t.refused || 'Refused') : (t.attention || 'Attention'));
		if (live) live.textContent = prefix + ': ' + text;
		feedback(state);
		const title = (details && details.title) || (state === 'success' ? (t.admitted || t.ok || 'OK') : (state === 'error' ? (t.notAdmitted || t.refused || 'Refused') : (t.attention || 'Attention')));
		showOverlay(state, title, (details && details.lines) || [text]);
	}
	// Short help code ("K7QM-4XWA"): 8 characters without 0/O/1/I; only checkable online.
	function looksLikeHelpCode(value) {
		return /^[2-9A-HJ-NP-Z]{8}$/.test(String(value || '').toUpperCase().replace(/[\s\-_.]+/g, ''));
	}
	/*
	 * A547: "Rückgängig (30 s)" under an admission. Online admissions are taken back on the server with
	 * a required reason (preset or typed); the server allows it only for the own admission shortly
	 * after the scan and checks area and refund state (Checkin::undo_guard()). A scan queued offline
	 * was never booked: it is just removed from the queue.
	 * what: { publicId } (online admission) or { queuedKey } (offline queue entry).
	 */
	function offerUndo(what) {
		if (!what || (!what.queuedKey && !(what.publicId && window.WPCAP_SCANNER.undoUrl))) return;
		const offer = { started: Date.now() };
		undoOffer = offer;
		const box = document.createElement('div');
		box.className = 'wpcap-undo';
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'button wpcap-undo-button';
		box.appendChild(button);
		result.appendChild(box);
		function label() {
			const left = undoSecondsLeft(offer.started, Date.now());
			button.textContent = (t.undo || 'Undo (%d s)').replace('%d', String(left));
			return left;
		}
		function tick() {
			if (undoOffer !== offer || offer.open) return;
			if (label() <= 0) { box.hidden = true; undoOffer = null; return; }
			window.setTimeout(tick, 1000);
		}
		label();
		window.setTimeout(tick, 1000);
		button.addEventListener('click', function () {
			if (undoOffer !== offer || undoSecondsLeft(offer.started, Date.now()) <= 0) return;
			if (what.queuedKey) {
				removeSent([what.queuedKey]);
				updateQueueButton();
				renderCounter();
				show('warning', t.undoneQueued || 'Offline admission discarded.');
				input.focus();
				return;
			}
			offer.open = true;
			button.hidden = true;
			reasonForm(box, what.publicId);
		});
	}
	function reasonForm(box, publicId) {
		const label = document.createElement('label');
		label.textContent = (t.undoReason || 'Reason') + ' ';
		const select = document.createElement('select');
		const options = [['', t.undoChoose || '—']].concat((window.WPCAP_SCANNER.undoReasons || []).map(function (r) { return [r, r]; }), [['__other', t.undoOther || 'Other …']]);
		options.forEach(function (entry) {
			const option = document.createElement('option');
			option.value = entry[0];
			option.textContent = entry[1];
			select.appendChild(option);
		});
		label.appendChild(select);
		const other = document.createElement('input');
		other.type = 'text';
		other.maxLength = 255;
		other.hidden = true;
		other.setAttribute('aria-label', t.undoOther || 'Other reason');
		select.addEventListener('change', function () { other.hidden = select.value !== '__other'; if (!other.hidden) other.focus(); });
		const confirm = document.createElement('button');
		confirm.type = 'button';
		confirm.className = 'button wpcap-undo-confirm';
		confirm.textContent = t.undoConfirm || 'Undo admission';
		const note = document.createElement('span');
		note.className = 'wpcap-undo-note';
		confirm.addEventListener('click', async function () {
			const reason = undoReason(select.value, other.value);
			if (!reason) { note.textContent = ' ' + (t.undoNeedReason || 'Please choose a reason.'); return; }
			confirm.disabled = true;
			try {
				const response = await fetch(window.WPCAP_SCANNER.undoUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce }, body: JSON.stringify({ ticket_public_id: publicId, reason: reason, area_id: areaId }) });
				const data = await response.json().catch(function () { return null; });
				if (response.ok && data && data.result === 'undone') {
					show('warning', t.undone || 'Admission undone.');
					refreshCounter();
				} else {
					show('error', (data && typeof data.message === 'string' && data.message) || t.systemError || 'Error');
				}
			} catch (error) {
				show('warning', (t.systemError || 'System error.') + ' (' + error.message + ')');
			}
			input.focus();
		});
		box.appendChild(label);
		box.appendChild(other);
		box.appendChild(confirm);
		box.appendChild(note);
		select.focus();
	}
	let stream = null;
	let locked = false;
	let lastToken = '';
	let lastTokenAt = 0;
	// Admission module: entrance area, check-in/check-out mode, idempotent offline sync.
	const adm = window.WPCAP_ADMISSION || null;
	// Entrance and date choice per site: area and occurrence ids are site-local (A472).
	const prefKey = 'wpcap-scanner-prefs-v2-' + site;
	let prefs = {};
	try { prefs = JSON.parse(window.localStorage.getItem(prefKey) || '{}') || {}; } catch (ignore) { prefs = {}; }
	function savePrefs() { try { window.localStorage.setItem(prefKey, JSON.stringify(prefs)); } catch (ignore) {} }
	let areaId = 0;
	let mode = 'in';
	// Date selection: with a date chosen the server refuses tickets of other dates and names their date.
	let occurrenceId = 0;
	(function occurrenceSelect() {
		const occ = window.WPCAP_SCANNER_OCCURRENCES;
		if (!occ || !occ.items || !occ.items.length) return;
		const label = document.createElement('label');
		label.className = 'wpcap-scanner-occurrence';
		label.textContent = (occ.label || 'Date') + ' ';
		const select = document.createElement('select');
		const all = document.createElement('option');
		all.value = '0';
		all.textContent = occ.all || '—';
		select.appendChild(all);
		occ.items.forEach(function (item) {
			const option = document.createElement('option');
			option.value = String(item.id);
			option.textContent = item.label;
			select.appendChild(option);
		});
		if (prefs.occurrence && occ.items.some(function (i) { return String(i.id) === String(prefs.occurrence); })) select.value = String(prefs.occurrence);
		occurrenceId = parseInt(select.value, 10) || 0;
		select.addEventListener('change', function () { occurrenceId = parseInt(select.value, 10) || 0; prefs.occurrence = occurrenceId; savePrefs(); refreshCounter(); });
		label.appendChild(select);
		root.insertBefore(label, form);
	}());
	function uuid() {
		if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
		return 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2);
	}
	if (adm) {
		const at = (adm.i18n || {});
		const bar = document.createElement('div');
		bar.className = 'wpcap-scanner-options';
		if (adm.areas && adm.areas.length) {
			const label = document.createElement('label');
			label.textContent = (at.area || 'Area') + ' ';
			const select = document.createElement('select');
			const none = document.createElement('option');
			none.value = '0';
			none.textContent = at.noArea || '—';
			select.appendChild(none);
			adm.areas.forEach(function (area) {
				const option = document.createElement('option');
				option.value = String(area.id);
				option.textContent = area.label;
				select.appendChild(option);
			});
			if (prefs.area && adm.areas.some(function (a) { return String(a.id) === String(prefs.area); })) select.value = String(prefs.area);
			areaId = parseInt(select.value, 10) || 0;
			select.addEventListener('change', function () { areaId = parseInt(select.value, 10) || 0; prefs.area = areaId; savePrefs(); });
			label.appendChild(select);
			bar.appendChild(label);
		}
		['in', 'out'].forEach(function (value) {
			const label = document.createElement('label');
			const radio = document.createElement('input');
			radio.type = 'radio';
			radio.name = 'wpcap-scan-mode';
			radio.value = value;
			radio.checked = value === 'in';
			radio.addEventListener('change', function () { if (radio.checked) { mode = value; root.dataset.mode = value; updateModeBanner(); } });
			label.appendChild(radio);
			label.appendChild(document.createTextNode(' ' + (value === 'in' ? (at.modeIn || 'In') : (at.modeOut || 'Out')) + ' '));
			bar.appendChild(label);
		});
		root.insertBefore(bar, form);
	}
	// Always visible which way staff are scanning (check-out looks different, so it is never used by mistake).
	function updateModeBanner() {
		if (!modeBanner || !adm) return;
		const at = adm.i18n || {};
		modeBanner.hidden = false;
		modeBanner.dataset.mode = mode;
		modeBanner.textContent = (t.modeBanner || '%s').replace('%s', mode === 'out' ? (at.modeOut || 'Out') : (at.modeIn || t.modeIn || 'In'));
	}
	root.dataset.mode = mode;
	updateModeBanner();
	// Offline queue: every scan carries its own idempotency key; nothing is truncated. Queued items
	// hold raw ticket codes, so they expire after QUEUE_MAX_AGE (default 24 h, with a visible
	// warning) and are removed only after the server confirmed them (matched by key).
	const QUEUE_MAX_AGE = Math.max(1, parseInt(privacy.maxAgeHours, 10) || 24) * 3600 * 1000;
	const SYNC_CHUNK = 100;
	let syncing = false;
	function normalizeItem(item) {
		if (typeof item === 'string') return { token: item, idempotency_key: uuid(), scanned_at: null, stored_at: Date.now(), area_id: 0, direction: 'in' };
		if (!item || typeof item !== 'object' || !item.token) return null;
		if (!item.idempotency_key) item.idempotency_key = uuid();
		if (!item.stored_at) item.stored_at = item.scanned_at ? Date.parse(item.scanned_at) || Date.now() : Date.now();
		return item;
	}
	// key: the idempotency key of the online attempt (A541) – if that request reached the server after
	// all, the later upload is recognised as the same scan instead of a conflicting second admission.
	function enqueue(token, key) {
		const items = queue();
		items.push({ token: token, idempotency_key: key || uuid(), scanned_at: new Date().toISOString(), stored_at: Date.now(), area_id: areaId, occurrence_id: occurrenceId, direction: mode });
		saveQueue(items);
		show('warning', t.queued || 'Saved offline.');
		offerUndo({ queuedKey: items[items.length - 1].idempotency_key });
		updateQueueButton();
		renderCounter();
	}
	// Offline the code cannot be checked: never shown as admitted. Only codes that look like a ticket are
	// offered, and only after an explicit staff decision ("admit after a visual check") are they queued
	// for the later server check. Reliable offline admission: the offline scanner app with a ticket package.
	// Signed QR payload (PC1.<claims>.<signature>, setting "signed_qr"); verified by the server.
	const SIGNED_QR = /PC1\.[A-Za-z0-9_-]{16,700}\.[A-Za-z0-9_-]{86}/;
	// key: idempotency key of the online attempt that did not get an answer (A541); reason: optional
	// prefix ("no answer from the server") shown before the offline notice.
	function offline(token, key, reason) {
		if (looksLikeHelpCode(token)) {
			show('warning', t.helpOffline || 'Help codes can only be checked online.');
			return;
		}
		// Signed QR payload (PC1.<claims>.<signature>, setting "signed_qr"); verified by the server.
		const signed = String(token || '').match(SIGNED_QR);
		const code = signed ? signed[0] : scanCode(token).code;
		if (!code) {
			show('error', t.invalid || 'Invalid ticket.');
			return;
		}
		// A542: the same code was already admitted offline on this device (queued, not yet uploaded):
		// refused in red with the time, never offered again for "admit after visual check".
		const earlier = mode === 'in' ? queuedAdmission(queue(), code) : null;
		if (earlier) {
			const line = (t.queuedAlready || 'Already admitted offline on this device at %s.').replace('%s', when(earlier.scanned_at) || '—');
			show('error', line, { lines: [line] });
			return;
		}
		show('warning', (reason ? reason + ' ' : '') + (t.unchecked || 'NOT CHECKED (offline).'));
		const admit = document.createElement('button');
		admit.type = 'button';
		admit.className = 'button wpcap-admit-unchecked';
		admit.textContent = t.admitUnchecked || 'Admit after visual check';
		admit.addEventListener('click', function () {
			admit.disabled = true;
			// Checked again at the moment of the decision (two taps, or a second scan meanwhile).
			if (mode === 'in' && queuedAdmission(queue(), code)) { offline(token, key, reason); return; }
			enqueue(token, key);
			input.focus();
		});
		result.appendChild(document.createTextNode(' '));
		result.appendChild(admit);
	}
	function queue() {
		let stored;
		try { stored = JSON.parse(window.localStorage.getItem(queueKey) || '[]'); } catch (ignore) { return []; }
		return Array.isArray(stored) ? stored.map(normalizeItem).filter(Boolean) : [];
	}
	function saveQueue(items) { try { window.localStorage.setItem(queueKey, JSON.stringify(items)); } catch (ignore) {} }
	function dropExpired() {
		const items = queue();
		const fresh = items.filter(function (item) { return Date.now() - item.stored_at <= QUEUE_MAX_AGE; });
		if (fresh.length !== items.length) {
			saveQueue(fresh);
			const dropped = items.length - fresh.length;
			// A383: singular wording for exactly one scan (expiredOne), otherwise the plural.
			const pick = function (src) { return src && ((1 === dropped && src.expiredOne) || src.expired); };
			show('error', (pick(privacy.i18n) || pick(adm && adm.i18n) || pick(t) || '%d offline scans older than 24 hours were discarded without upload.').replace('%d', dropped));
		}
	}
	const syncButton = document.createElement('button');
	syncButton.type = 'button';
	syncButton.className = 'wpcap-sync';
	syncButton.textContent = t.syncButton || 'Sync offline scans';
	syncButton.hidden = true;
	root.appendChild(syncButton);
	function updateQueueButton() { const count = queue().length; syncButton.hidden = !count; syncButton.textContent = ((1 === count && t.syncCountOne) || t.syncCount || '%d').replace('%d', count); }
	function removeSent(keys) {
		saveQueue(queue().filter(function (item) { return keys.indexOf(item.idempotency_key) === -1; }));
	}
	async function syncQueue() {
		if (syncing) return;
		dropExpired();
		const items = queue();
		if (!items.length || !navigator.onLine) { updateQueueButton(); return; }
		syncing = true;
		syncButton.disabled = true;
		let sent = 0;
		let conflicts = 0;
		try {
			for (let i = 0; i < items.length; i += SYNC_CHUNK) {
				const chunk = items.slice(i, i + SYNC_CHUNK);
				const response = adm
					? await fetch(adm.offlineSyncUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce }, body: JSON.stringify({ scans: chunk.map(function (item) { return { token: item.token, idempotency_key: item.idempotency_key, scanned_at: item.scanned_at, area_id: item.area_id || 0, occurrence_id: item.occurrence_id || 0, direction: item.direction || 'in' }; }) }) })
					: await fetch(WPCAP_SCANNER.offlineSyncUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce }, body: JSON.stringify({ scans: chunk.map(function (item) { return { token: item.token, idempotency_key: item.idempotency_key, occurrence_id: item.occurrence_id || 0 }; }) }) });
				const data = await response.json().catch(function () { return null; });
				// Only a JSON answer with a results list confirms the upload (A315): a login, proxy or WAF page
				// with status 200 must not remove queued scans.
				if (!response.ok || !data || !Array.isArray(data.results)) throw new Error((data && data.message) || t.syncFailed || 'Sync failed.');
				// Remove exactly the uploaded items; scans queued meanwhile stay, and so do scans the server
				// could not book (retryable, A345) – they are uploaded again with the next sync.
				const retry = data.results.filter(function (item) { return item && item.retryable; }).map(function (item) { return item.idempotency_key; });
				const done = chunk.map(function (item) { return item.idempotency_key; }).filter(function (key) { return retry.indexOf(key) === -1; });
				removeSent(done);
				sent += done.length;
				conflicts += data.results.filter(function (item) { return item.status === 'conflict'; }).length;
			}
			show(conflicts ? 'error' : 'success', ((1 === sent && t.syncedOne) || t.synced || '%1$d / %2$d').replace('%1$d', sent).replace('%2$d', conflicts));
		} catch (error) { show('error', error.message); }
		finally {
			syncing = false;
			syncButton.disabled = false;
			updateQueueButton();
			refreshCounter();
			// The (now hidden) sync button may have had keyboard focus; continue at the code input.
			if (!document.activeElement || document.activeElement === document.body) input.focus();
		}
	}
	/*
	 * A546: check-in counter of the chosen date (GET /scanner/stats), refreshed after every admission
	 * or undo, on a date change and every 30 s. Offline it keeps the last server value ("Stand …")
	 * and adds the admissions queued on this device for that date.
	 */
	const counter = root.querySelector('.wpcap-scan-counter');
	let lastCounts = null;
	let counterTimer = 0;
	function queuedFor(occ) {
		return queue().filter(function (item) { return (item.direction || 'in') === 'in' && (parseInt(item.occurrence_id, 10) || 0) === occ; }).length;
	}
	function renderCounter() {
		if (!counter) return;
		if (!occurrenceId || !lastCounts || lastCounts.occurrence !== occurrenceId) { counter.hidden = true; return; }
		counter.hidden = false;
		counter.textContent = counterText(t, lastCounts.admitted, lastCounts.total, queuedFor(occurrenceId), lastCounts.fresh ? '' : when(new Date(lastCounts.at).toISOString()));
	}
	async function refreshCounter() {
		window.clearTimeout(counterTimer);
		counterTimer = window.setTimeout(refreshCounter, 30000);
		if (!counter || !window.WPCAP_SCANNER.statsUrl) return;
		if (!occurrenceId) { renderCounter(); return; }
		const occ = occurrenceId;
		if (navigator.onLine) {
			try {
				const base = window.WPCAP_SCANNER.statsUrl;
				const response = await fetch(base + (base.indexOf('?') === -1 ? '?' : '&') + 'occurrence_id=' + occ, { method: 'GET', headers: { 'X-WP-Nonce': WPCAP_SCANNER.nonce } });
				const data = await response.json().catch(function () { return null; });
				if (response.ok && data && typeof data.admitted === 'number' && typeof data.total === 'number') {
					lastCounts = { occurrence: occ, admitted: data.admitted, total: data.total, at: Date.now(), fresh: true };
				} else if (lastCounts) { lastCounts.fresh = false; }
			} catch (ignore) { if (lastCounts) lastCounts.fresh = false; }
		} else if (lastCounts) { lastCounts.fresh = false; }
		renderCounter();
	}
	dropExpired();
	syncButton.addEventListener('click', syncQueue);
	window.addEventListener('online', syncQueue);
	updateQueueButton();
	refreshCounter();

	// Note under the current result that a scan was thrown away (the result itself stays visible).
	function discarded(text) {
		const note = document.createElement('span');
		note.className = 'wpcap-scan-discarded';
		note.textContent = ' ⚠ ' + text;
		result.appendChild(note);
		if (live) live.textContent = (t.attention || 'Attention') + ': ' + text;
		feedback('warning');
	}
	// A271: the code field is emptied for every scan as soon as it is taken (before the answer), so a
	// keyboard wedge scanner never types the next code behind the previous one.
	function takeInput() {
		const value = input.value;
		input.value = '';
		return value;
	}
	/* Shows the answer of a check-in (scan or search result): refusal, system warning or admission. */
	function presentAnswer(status, data) {
		const kind = classifyAnswer(status, data);
		const message = data && typeof data.message === 'string' ? data.message : '';
		if (kind === 'session') {
			show('warning', t.sessionExpired || 'Session expired – reload the page.');
			return;
		}
		if (kind === 'rate') {
			// Too many help-code entries: not a verdict on the ticket.
			show('warning', message || t.rateLimited || 'Too many attempts.');
			return;
		}
		if (kind === 'system') {
			// Server fault, busy database, WAF/proxy page, unknown error: not a verdict on the ticket –
			// never "no entry" (A315).
			const why = data ? (t.systemError || 'System error – scan again.') + (message && status < 500 ? ' (' + message + ')' : '') : (t.badAnswer || 'Unexpected answer from the server (HTTP %d) – scan again.').replace('%d', String(status));
			show('warning', why);
			return;
		}
		if (kind === 'refusal') {
			// Refusal of a known ticket: name the ticket and when it was first used.
			const info = data.data || {};
			const refusal = [message || t.invalid || 'Invalid ticket.'].concat(info.event ? [info.event + (info.ticket ? ' — ' + info.ticket : '')] : [], info.attendee_name ? [info.attendee_name] : [], historyLines(info, false), info.staff_hint ? ['⚠ ' + info.staff_hint] : []);
			show('error', refusal.join(' — '), { lines: refusal });
			return;
		}
		const out = data.status === 'checked_out';
		// ID/age check of the ticket type ("Ausweis prüfen · Mindestalter 18") for the staff.
		const hint = data.staff_hint && !out ? '⚠ ' + data.staff_hint : '';
		const lines = [[data.event, data.ticket].filter(Boolean).join(' — '), data.attendee_name || ''].concat(out ? [] : historyLines(data, true), hint ? [hint] : [], data.help_code && !out ? ['⚠ ' + (t.viaHelpCode || 'Help code')] : []).filter(Boolean);
		show('success', (out ? ((adm && adm.i18n && adm.i18n.checkedOut) || 'Checked out') + ': ' : '') + lines.join(' — '), { title: out ? (t.checkedOutTitle || 'OUT') : (t.admitted || 'OK'), lines: lines });
		if (!out) offerUndo({ publicId: data.ticket_public_id });
		if (!out && Array.isArray(data.group) && data.group.length) offerGroup(data.group);
		refreshCounter();
	}
	/*
	 * A596: group check-in. After an admission the server lists further valid tickets of the same order for this
	 * date; staff tick the people present and admit them in one go (each through /scanner/check-in-ticket, so
	 * every rule of a scan applies).
	 */
	function offerGroup(group) {
		if (!window.WPCAP_SCANNER.checkInTicketUrl) return;
		const box = document.createElement('fieldset');
		box.className = 'wpcap-group';
		const legend = document.createElement('legend');
		legend.textContent = (t.groupTitle || 'More tickets in this order (%d)').replace('%d', String(group.length));
		box.appendChild(legend);
		const boxes = group.map(function (item) {
			const label = document.createElement('label');
			label.className = 'wpcap-group-item';
			const check = document.createElement('input');
			check.type = 'checkbox';
			check.checked = true;
			check.value = item.ticket_public_id;
			label.appendChild(check);
			label.appendChild(document.createTextNode(' ' + [item.ticket, item.attendee_name || ''].filter(Boolean).join(' – ')));
			box.appendChild(label);
			return check;
		});
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'button button-primary wpcap-group-checkin';
		const label = function () { button.textContent = (t.groupCheckin || 'Check in selected (%d)').replace('%d', String(boxes.filter(function (b) { return b.checked; }).length)); };
		boxes.forEach(function (b) { b.addEventListener('change', label); });
		label();
		button.addEventListener('click', async function () {
			const ids = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
			if (!ids.length) return;
			button.disabled = true;
			let ok = 0;
			let failed = 0;
			for (const id of ids) {
				try {
					const response = await fetch(window.WPCAP_SCANNER.checkInTicketUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce }, body: JSON.stringify({ ticket_public_id: id, occurrence_id: occurrenceId, area_id: areaId, idempotency_key: uuid() }) });
					if (response.ok) { ok++; } else { failed++; }
				} catch (ignore) { failed++; }
			}
			box.remove();
			const lines = [(t.groupDone || '%d more admitted').replace('%d', String(ok))].concat(failed ? [(t.groupFailed || '%d not admitted – scan them individually').replace('%d', String(failed))] : []);
			show(failed ? 'warning' : 'success', lines.join(' — '), { lines: lines });
			refreshCounter();
			input.focus();
		});
		box.appendChild(button);
		result.appendChild(box);
	}
	async function submitToken(token) {
		token = String(token || '').trim();
		if (!token) return;
		if (locked) {
			// A271: a scan during the lock window is discarded visibly, never silently kept for later.
			discarded(t.discarded || 'Scan discarded – scan again.');
			return;
		}
		unlockAudio();
		const parsed = scanCode(token);
		if (parsed.ambiguous) {
			show('error', t.multipleCodes || 'Several ticket codes in one input – scan each ticket on its own.');
			return;
		}
		// One idempotency key per scan: reused for the offline queue when the answer is lost (A541).
		const key = uuid();
		if (!navigator.onLine) { offline(token, key); return; }
		locked = true;
		show('busy', t.checking || '…');
		const controller = window.AbortController ? new window.AbortController() : null;
		const timer = controller ? window.setTimeout(function () { controller.abort(); }, REQUEST_TIMEOUT_MS) : 0;
		try {
			let response;
			let data;
			try {
				const url = adm ? (mode === 'out' ? adm.checkoutUrl : adm.checkinUrl) : WPCAP_SCANNER.checkinUrl;
				const body = adm ? { token: token, area_id: areaId, occurrence_id: occurrenceId, idempotency_key: key } : { token: token, occurrence_id: occurrenceId, idempotency_key: key };
				response = await fetch(url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce },
					body: JSON.stringify(body),
					signal: controller ? controller.signal : undefined
				});
				// The body is read under the same timeout (A540).
				const text = await response.text();
				try { data = JSON.parse(text); } catch (ignore) { data = null; }
				if (!data || typeof data !== 'object' || Array.isArray(data)) data = null;
			} catch (networkError) {
				// No answer (network down although the browser reports "online", or no answer within
				// REQUEST_TIMEOUT_MS, A540): the offline decision, with the key of this attempt.
				offline(token, key, controller && controller.signal.aborted ? (t.timeout || 'No answer from the server.') : '');
				return;
			} finally {
				if (timer) window.clearTimeout(timer);
			}
			presentAnswer(response.status, data);
		} catch (error) {
			show('warning', (t.systemError || 'System error – scan again.') + ' (' + error.message + ')');
		} finally {
			window.clearTimeout(timer);
			window.setTimeout(function () { locked = false; }, 1400);
		}
	}

	form.addEventListener('submit', function (event) {
		event.preventDefault();
		submitToken(takeInput());
	});

	/*
	 * A544: manual search (name, e-mail, order number) for guests without a readable code. The server
	 * searches only fields the person may see (Pii) in the chosen date, or in the dates of the date
	 * list; "Einchecken" uses the same rules as a scan (logged as manual check-in). Online only – the
	 * offline scanner app searches its package offline.
	 */
	const searchForm = root.querySelector('.wpcap-search-form');
	const searchList = root.querySelector('.wpcap-search-results');
	function searchLine(item) {
		const parts = [[item.event, item.ticket].filter(Boolean).join(' — '), item.attendee_name || '', item.email || '', item.order_ref ? (t.searchOrder || '%s').replace('%s', item.order_ref) : ''];
		if (item.status !== 'valid') parts.push(item.status === 'checked_in' ? (t.searchUsed || 'used') : (t.searchVoid || 'void'));
		return parts.filter(Boolean).join(' · ');
	}
	async function checkInResult(item, button) {
		if (locked) { discarded(t.discarded || 'Scan discarded – scan again.'); return; }
		unlockAudio();
		locked = true;
		button.disabled = true;
		show('busy', t.checking || '…');
		try {
			const response = await fetch(window.WPCAP_SCANNER.checkInTicketUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce }, body: JSON.stringify({ ticket_public_id: item.ticket_public_id, occurrence_id: occurrenceId, area_id: areaId, idempotency_key: uuid() }) });
			const text = await response.text();
			let data;
			try { data = JSON.parse(text); } catch (ignore) { data = null; }
			presentAnswer(response.status, data && typeof data === 'object' && !Array.isArray(data) ? data : null);
		} catch (error) {
			show('warning', (t.searchOffline || 'Search works online only.') + ' (' + error.message + ')');
		} finally {
			window.setTimeout(function () { locked = false; }, 1400);
			input.focus();
		}
	}
	function renderSearch(data) {
		searchList.textContent = '';
		const items = data && Array.isArray(data.results) ? data.results : [];
		if (!items.length) {
			const none = document.createElement('li');
			none.textContent = t.searchNone || 'No ticket found.';
			searchList.appendChild(none);
		}
		items.forEach(function (item) {
			const li = document.createElement('li');
			const text = document.createElement('span');
			text.textContent = searchLine(item);
			li.appendChild(text);
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'button wpcap-search-checkin';
			button.textContent = t.searchCheckin || 'Check in';
			button.addEventListener('click', function () { checkInResult(item, button); });
			li.appendChild(button);
			searchList.appendChild(li);
		});
		if (data && data.more) {
			const more = document.createElement('li');
			more.textContent = t.searchMore || 'More results – please search more precisely.';
			searchList.appendChild(more);
		}
	}
	if (searchForm && searchList && window.WPCAP_SCANNER.searchUrl) {
		const searchInput = searchForm.querySelector('[name="q"]');
		searchForm.addEventListener('submit', async function (event) {
			event.preventDefault();
			const q = String(searchInput.value || '').trim();
			if (!q) return;
			if (!navigator.onLine) { show('warning', t.searchOffline || 'Search works online only.'); return; }
			const base = window.WPCAP_SCANNER.searchUrl;
			try {
				const response = await fetch(base + (base.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q) + '&occurrence_id=' + occurrenceId, { method: 'GET', headers: { 'X-WP-Nonce': WPCAP_SCANNER.nonce } });
				const data = await response.json().catch(function () { return null; });
				if (!response.ok || !data || !Array.isArray(data.results)) {
					show('warning', (data && typeof data.message === 'string' && data.message) || t.systemError || 'System error.');
					return;
				}
				renderSearch(data);
			} catch (error) {
				show('warning', (t.searchOffline || 'Search works online only.') + ' (' + error.message + ')');
			}
		});
	}

	// Native BarcodeDetector where available (Chrome/Android); otherwise jsQR on canvas frames (Safari/iOS, Firefox).
	function loadJsQr() {
		if (window.jsQR) return Promise.resolve();
		return new Promise(function (resolve, reject) {
			const script = document.createElement('script');
			script.src = window.WPCAP_SCANNER.jsqrUrl;
			script.onload = function () { resolve(); };
			script.onerror = function () { reject(new Error('jsQR')); };
			document.head.appendChild(script);
		});
	}
	async function makeDetector() {
		if ('BarcodeDetector' in window) {
			try {
				const formats = await window.BarcodeDetector.getSupportedFormats();
				if (formats.indexOf('qr_code') !== -1) {
					const native = new window.BarcodeDetector({ formats: ['qr_code'] });
					return async function () { const codes = await native.detect(video); return codes.length ? String(codes[0].rawValue || '') : ''; };
				}
			} catch (ignore) {}
		}
		await loadJsQr();
		const canvas = document.createElement('canvas');
		const context = canvas.getContext('2d', { willReadFrequently: true });
		return async function () {
			if (!video.videoWidth) return '';
			// Downscale large frames: faster on phones and still enough for ticket QR codes.
			const scale = Math.min(1, 640 / Math.max(video.videoWidth, video.videoHeight));
			canvas.width = Math.round(video.videoWidth * scale);
			canvas.height = Math.round(video.videoHeight * scale);
			context.drawImage(video, 0, 0, canvas.width, canvas.height);
			const image = context.getImageData(0, 0, canvas.width, canvas.height);
			const code = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
			return code ? String(code.data || '') : '';
		};
	}

	const startButton = root.querySelector('.wpcap-camera-start');
	const stopButton = root.querySelector('.wpcap-camera-stop');
	const torchButton = root.querySelector('.wpcap-camera-torch');
	let wakeLock = null;
	async function keepAwake() {
		try { if (navigator.wakeLock && !wakeLock) { wakeLock = await navigator.wakeLock.request('screen'); wakeLock.addEventListener('release', function () { wakeLock = null; }); } } catch (ignore) {}
	}
	/**
	 * Stops the camera: every track of the stream is ended (the browser's camera indicator goes off),
	 * the video is detached and hidden and the start button is usable again. Runs on "stop", when the
	 * page is hidden or left (pagehide, visibilitychange; audit FE-6) and after camera errors.
	 */
	function stopCamera() {
		const current = stream;
		stream = null;
		if (current && current.getTracks) current.getTracks().forEach(function (track) { try { track.stop(); } catch (ignore) {} });
		if (video) {
			try { if (video.pause) video.pause(); } catch (ignore) {}
			video.hidden = true;
			video.srcObject = null;
		}
		if (startButton) startButton.disabled = false;
		if (stopButton) stopButton.hidden = true;
		if (torchButton) { torchButton.hidden = true; torchButton.setAttribute('aria-pressed', 'false'); }
		if (wakeLock) { wakeLock.release().catch(function () {}); wakeLock = null; }
		if (startButton && document.visibilityState !== 'hidden') startButton.focus();
	}
	window.addEventListener('pagehide', function () { cameraRun++; starting = false; stopCamera(); });
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') { cameraRun++; starting = false; stopCamera(); }
		else if (stream) keepAwake();
	});
	if (stopButton) stopButton.addEventListener('click', stopCamera);
	if (torchButton) {
		torchButton.addEventListener('click', async function () {
			const track = stream && stream.getVideoTracks()[0];
			if (!track) return;
			const on = torchButton.getAttribute('aria-pressed') !== 'true';
			try { await track.applyConstraints({ advanced: [{ torch: on }] }); torchButton.setAttribute('aria-pressed', on ? 'true' : 'false'); } catch (ignore) { torchButton.hidden = true; }
		});
	}

	// A316: one camera start at a time. The button is locked at once (a second tap while the permission
	// prompt or getUserMedia() is pending would open a second stream that nobody stops); each start has
	// a run number, so a stream that arrives after "stop" or after a newer start is stopped right away.
	let cameraRun = 0;
	let starting = false;
	function stopTracks(s) { if (s) s.getTracks().forEach(function (track) { track.stop(); }); }
	if (stopButton) stopButton.addEventListener('click', function () { cameraRun++; starting = false; });
	startButton.addEventListener('click', async function (event) {
		const button = event.currentTarget;
		unlockAudio();
		if (starting || stream) return;
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			show('error', t.noCamera || 'Camera scanning is not supported.');
			return;
		}
		const run = ++cameraRun;
		starting = true;
		// A disabled button loses keyboard focus; keep the manual input as the next stop.
		const hadFocus = document.activeElement === button;
		button.disabled = true;
		if (hadFocus) input.focus();
		let mine = null;
		try {
			mine = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
			if (run !== cameraRun) { stopTracks(mine); return; }
			if (stream && stream !== mine) stopTracks(stream);
			stream = mine;
			video.srcObject = stream;
			video.setAttribute('playsinline', '');
			video.muted = true;
			video.hidden = false;
			await video.play();
			const detect = await makeDetector();
			if (run !== cameraRun || stream !== mine) { stopTracks(mine); return; }
			starting = false;
			if (stopButton) stopButton.hidden = false;
			const track = stream.getVideoTracks()[0];
			let caps = {};
			try { caps = (track && track.getCapabilities && track.getCapabilities()) || {}; } catch (ignore) { caps = {}; }
			if (torchButton) torchButton.hidden = !caps.torch;
			// Continuous autofocus where the camera supports it (Chrome/Android).
			if (track && Array.isArray(caps.focusMode) && caps.focusMode.indexOf('continuous') !== -1 && track.applyConstraints) {
				track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});
			}
			keepAwake();
			let busy = false;
			const scan = async function () {
				if (!stream || stream !== mine) return;
				if (!busy && !locked) {
					busy = true;
					try {
						const raw = await detect();
						const signedMatch = raw.match(SIGNED_QR);
						const match = signedMatch ? signedMatch[0] : scanCode(raw).code;
						const now = Date.now();
						// The same QR often stays in view after a scan: it is submitted again only after it was
						// out of view for 3 s (sliding window), so "already used" never overwrites the green result.
						if (match && match === lastToken && now - lastTokenAt < 3000) {
							lastTokenAt = now;
						} else if (match) {
							lastToken = match;
							lastTokenAt = now;
							await submitToken(match);
						}
					} catch (ignore) {}
					busy = false;
				}
				window.requestAnimationFrame(scan);
			};
			scan();
		} catch (error) {
			if (run !== cameraRun) { stopTracks(mine); return; }
			starting = false;
			show('error', (t.cameraError || 'Camera error:') + ' ' + error.message);
			if (mine && stream !== mine) stopTracks(mine);
			stopCamera();
		}
	});
	// Test hook for tests/js (node:test); `module` does not exist in the browser, so this is a no-op there.
	if (typeof module === 'object' && module && module.exports) { module.exports = { queueKey: queueKey, queue: queue, normalizeItem: normalizeItem, enqueue: enqueue, offline: offline, dropExpired: dropExpired, syncQueue: syncQueue, submitToken: submitToken, beep: beep }; }
})();
