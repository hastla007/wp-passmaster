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
	window.wpcapScannerLogic = { scanCode: scanCode, classifyAnswer: classifyAnswer, queuedAdmission: queuedAdmission, REQUEST_TIMEOUT_MS: REQUEST_TIMEOUT_MS };

	const root = document.querySelector('.wpcap-scanner');
	if (!root || !window.WPCAP_SCANNER) return;
	const form = root.querySelector('.wpcap-scan-form');
	const input = form.querySelector('[name="token"]');
	const result = root.querySelector('.wpcap-scan-result');
	const live = root.querySelector('.wpcap-scan-live');
	const modeBanner = root.querySelector('.wpcap-scan-mode');
	const video = root.querySelector('.wpcap-camera');
	// Offline queue per WordPress user (keyed hash from the server, never the raw ID): a shared
	// device must not hand one user's queued ticket codes to the next. Queues of other users and
	// the old shared v1 queue are removed as soon as a different user opens the scanner (the
	// browser cannot be cleared on logout itself).
	const privacy = window.WPCAP_SCANNER_PRIVACY || {};
	const owner = String(privacy.owner || 'anonymous').replace(/[^A-Za-z0-9_-]/g, '');
	const queuePrefix = 'wpcap-offline-scans-v2-';
	const queueKey = queuePrefix + owner;
	const ownerKey = 'wpcap-scanner-owner';
	(function isolateQueue() {
		try {
			const ls = window.localStorage;
			const legacy = ls.getItem('wpcap-offline-scans-v1');
			const previousOwner = ls.getItem(ownerKey);
			// Legacy queue (before per-user keys): adopted once by the first user on this browser,
			// expired items are dropped by the age check below.
			if (legacy && !previousOwner && !ls.getItem(queueKey)) ls.setItem(queueKey, legacy);
			const foreign = [];
			for (let i = 0; i < ls.length; i++) {
				const key = ls.key(i);
				if (key && (key === 'wpcap-offline-scans-v1' || (key.indexOf(queuePrefix) === 0 && key !== queueKey))) foreign.push(key);
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
	function when(iso) {
		const date = new Date(iso);
		if (!iso || isNaN(date.getTime())) return '';
		try {
			return date.toDateString() === new Date().toDateString()
				? date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })
				: date.toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' });
		} catch (ignore) { return date.toLocaleString(); }
	}
	// Lines about earlier admissions ("Erstmals eingelassen", "Bereits eingelassen um 19:42 · Bereich: Nord").
	function historyLines(data, admitted) {
		const lines = [];
		if (!data) return lines;
		if (admitted) {
			if (data.first_admission) lines.push(t.firstAdmission || 'First admission');
			else if (data.admissions > 1 && data.first_checked_in_at) lines.push((t.reentry || '%1$s %2$s').replace('%1$s', String(data.admissions)).replace('%2$s', when(data.first_checked_in_at)));
		} else if (data.first_checked_in_at) {
			let line = (t.alreadyAt || '%s').replace('%s', when(data.first_checked_in_at));
			if (data.last_checked_in_at && data.admissions > 1) line += ', ' + (t.lastAt || '%s').replace('%s', when(data.last_checked_in_at));
			if (data.last_checked_in_area) line += ' · ' + (t.atArea || '%s').replace('%s', data.last_checked_in_area);
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
	// show(state, text[, details]): details = { title, lines } for the full-screen overlay.
	function show(state, text, details) {
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
	let stream = null;
	let locked = false;
	let lastToken = '';
	let lastTokenAt = 0;
	// Admission module: entrance area, check-in/check-out mode, idempotent offline sync.
	const adm = window.WPCAP_ADMISSION || null;
	const prefKey = 'wpcap-scanner-prefs-v1';
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
		select.addEventListener('change', function () { occurrenceId = parseInt(select.value, 10) || 0; prefs.occurrence = occurrenceId; savePrefs(); });
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
		updateQueueButton();
	}
	// Offline the code cannot be checked: never shown as admitted. Only codes that look like a ticket are
	// offered, and only after an explicit staff decision ("admit after a visual check") are they queued
	// for the later server check. Reliable offline admission: the offline scanner app with a ticket package.
	// key: idempotency key of the online attempt that did not get an answer (A541); reason: optional
	// prefix ("no answer from the server") shown before the offline notice.
	function offline(token, key, reason) {
		if (looksLikeHelpCode(token)) {
			show('warning', t.helpOffline || 'Help codes can only be checked online.');
			return;
		}
		const code = scanCode(token).code;
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
			show('error', ((privacy.i18n && privacy.i18n.expired) || (adm && adm.i18n && adm.i18n.expired) || t.expired || '%d offline scan(s) older than 24 hours were discarded without upload.').replace('%d', items.length - fresh.length));
		}
	}
	const syncButton = document.createElement('button');
	syncButton.type = 'button';
	syncButton.textContent = t.syncButton || 'Sync offline scans';
	syncButton.hidden = true;
	root.appendChild(syncButton);
	function updateQueueButton() { const count = queue().length; syncButton.hidden = !count; syncButton.textContent = (t.syncCount || '%d').replace('%d', count); }
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
					: await fetch(WPCAP_SCANNER.offlineSyncUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce }, body: JSON.stringify({ tokens: chunk.map(function (item) { return item.token; }) }) });
				const data = await response.json().catch(function () { return null; });
				// Only a JSON answer with a results list confirms the upload (A315): a login, proxy or WAF page
				// with status 200 must not remove queued scans.
				if (!response.ok || !data || !Array.isArray(data.results)) throw new Error((data && data.message) || t.syncFailed || 'Sync failed.');
				// Remove exactly the uploaded items; scans queued meanwhile stay.
				removeSent(chunk.map(function (item) { return item.idempotency_key; }));
				sent += chunk.length;
				conflicts += (data.results || []).filter(function (item) { return item.status === 'conflict'; }).length;
			}
			show(conflicts ? 'error' : 'success', (t.synced || '%1$d / %2$d').replace('%1$d', sent).replace('%2$d', conflicts));
		} catch (error) { show('error', error.message); }
		finally {
			syncing = false;
			syncButton.disabled = false;
			updateQueueButton();
			// The (now hidden) sync button may have had keyboard focus; continue at the code input.
			if (!document.activeElement || document.activeElement === document.body) input.focus();
		}
	}
	dropExpired();
	syncButton.addEventListener('click', syncQueue);
	window.addEventListener('online', syncQueue);
	updateQueueButton();

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
			}
			const kind = classifyAnswer(response.status, data);
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
				const why = data ? (t.systemError || 'System error – scan again.') + (message && response.status < 500 ? ' (' + message + ')' : '') : (t.badAnswer || 'Unexpected answer from the server (HTTP %d) – scan again.').replace('%d', String(response.status));
				show('warning', why);
				return;
			}
			if (kind === 'refusal') {
				// Refusal of a known ticket: name the ticket and when it was first used.
				const info = data.data || {};
				const refusal = [message || t.invalid || 'Invalid ticket.'].concat(info.event ? [info.event + (info.ticket ? ' — ' + info.ticket : '')] : [], historyLines(info, false), info.staff_hint ? ['⚠ ' + info.staff_hint] : []);
				show('error', refusal.join(' — '), { lines: refusal });
				return;
			}
			const out = data.status === 'checked_out';
			// ID/age check of the ticket type ("Ausweis prüfen · Mindestalter 18") for the staff.
			const hint = data.staff_hint && !out ? '⚠ ' + data.staff_hint : '';
			const lines = [[data.event, data.ticket].filter(Boolean).join(' — '), data.attendee_name || ''].concat(out ? [] : historyLines(data, true), hint ? [hint] : [], data.help_code && !out ? ['⚠ ' + (t.viaHelpCode || 'Help code')] : []).filter(Boolean);
			show('success', (out ? ((adm && adm.i18n && adm.i18n.checkedOut) || 'Checked out') + ': ' : '') + lines.join(' — '), { title: out ? (t.checkedOutTitle || 'OUT') : (t.admitted || 'OK'), lines: lines });
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
	document.addEventListener('visibilitychange', function () { if (stream && document.visibilityState === 'visible') keepAwake(); });
	function stopCamera() {
		if (stream) stream.getTracks().forEach(function (track) { track.stop(); });
		stream = null;
		video.hidden = true;
		video.srcObject = null;
		startButton.disabled = false;
		stopButton.hidden = true;
		torchButton.hidden = true;
		torchButton.setAttribute('aria-pressed', 'false');
		if (wakeLock) { wakeLock.release().catch(function () {}); wakeLock = null; }
		startButton.focus();
	}
	stopButton.addEventListener('click', stopCamera);
	torchButton.addEventListener('click', async function () {
		const track = stream && stream.getVideoTracks()[0];
		if (!track) return;
		const on = torchButton.getAttribute('aria-pressed') !== 'true';
		try { await track.applyConstraints({ advanced: [{ torch: on }] }); torchButton.setAttribute('aria-pressed', on ? 'true' : 'false'); } catch (ignore) { torchButton.hidden = true; }
	});

	startButton.addEventListener('click', async function (event) {
		const button = event.currentTarget;
		unlockAudio();
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			show('error', t.noCamera || 'Camera scanning is not supported.');
			return;
		}
		try {
			stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
			video.srcObject = stream;
			video.setAttribute('playsinline', '');
			video.muted = true;
			video.hidden = false;
			await video.play();
			const detect = await makeDetector();
			const hadFocus = document.activeElement === button;
			button.disabled = true;
			stopButton.hidden = false;
			const track = stream.getVideoTracks()[0];
			const caps = track && track.getCapabilities ? track.getCapabilities() : {};
			torchButton.hidden = !caps.torch;
			keepAwake();
			// A disabled button loses keyboard focus; keep the manual input as the next stop.
			if (hadFocus) input.focus();
			let busy = false;
			const scan = async function () {
				if (!stream) return;
				if (!busy && !locked) {
					busy = true;
					try {
						const raw = await detect();
						const match = scanCode(raw).code;
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
			show('error', (t.cameraError || 'Camera error:') + ' ' + error.message);
			if (stream) stopCamera();
		}
	});
})();
