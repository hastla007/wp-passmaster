(function () {
	'use strict';
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
	// sight of the screen a tone and a vibration.
	const ICONS = { success: '✓', error: '✕', warning: '!', busy: '…' };
	let audio = null;
	function unlockAudio() {
		// One AudioContext, created in a user gesture (camera start / submit): iOS keeps it silent otherwise.
		if (audio || !(window.AudioContext || window.webkitAudioContext)) return;
		try { audio = new (window.AudioContext || window.webkitAudioContext)(); } catch (ignore) { audio = null; }
	}
	function tone(frequencies) {
		if (!audio) return;
		try {
			if (audio.state === 'suspended') audio.resume();
			let at = audio.currentTime;
			frequencies.forEach(function (frequency) {
				const osc = audio.createOscillator();
				const gain = audio.createGain();
				osc.frequency.value = frequency;
				gain.gain.setValueAtTime(0.2, at);
				gain.gain.exponentialRampToValueAtTime(0.001, at + 0.18);
				osc.connect(gain).connect(audio.destination);
				osc.start(at);
				osc.stop(at + 0.2);
				at += 0.22;
			});
		} catch (ignore) {}
	}
	function feedback(state) {
		if (state === 'success') { tone([880]); if (navigator.vibrate) navigator.vibrate(80); }
		else if (state === 'error') { tone([220, 180]); if (navigator.vibrate) navigator.vibrate([200, 100, 200]); }
		else if (state === 'warning') { tone([440, 440]); if (navigator.vibrate) navigator.vibrate([120, 80, 120]); }
	}
	function show(state, text) {
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
		if (state !== 'busy') {
			const prefix = state === 'success' ? (t.ok || 'OK') : (state === 'error' ? (t.refused || 'Refused') : (t.attention || 'Attention'));
			if (live) live.textContent = prefix + ': ' + text;
			feedback(state);
		}
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
	function enqueue(token) {
		const items = queue();
		items.push({ token: token, idempotency_key: uuid(), scanned_at: new Date().toISOString(), stored_at: Date.now(), area_id: areaId, direction: mode });
		saveQueue(items);
		show('warning', t.queued || 'Saved offline.');
		input.value = '';
		updateQueueButton();
	}
	// Offline the code cannot be checked: never shown as admitted. Only codes that look like a ticket are
	// offered, and only after an explicit staff decision ("admit after a visual check") are they queued
	// for the later server check. Reliable offline admission: the offline scanner app with a ticket package.
	function offline(token) {
		if (!/(^|[^a-f0-9])[a-f0-9]{64}($|[^a-f0-9])/i.test(token)) {
			show('error', t.invalid || 'Invalid ticket.');
			return;
		}
		show('warning', t.unchecked || 'NOT CHECKED (offline).');
		const admit = document.createElement('button');
		admit.type = 'button';
		admit.className = 'button wpcap-admit-unchecked';
		admit.textContent = t.admitUnchecked || 'Admit after visual check';
		admit.addEventListener('click', function () { enqueue(token); input.focus(); });
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
					? await fetch(adm.offlineSyncUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce }, body: JSON.stringify({ scans: chunk.map(function (item) { return { token: item.token, idempotency_key: item.idempotency_key, scanned_at: item.scanned_at, area_id: item.area_id || 0, direction: item.direction || 'in' }; }) }) })
					: await fetch(WPCAP_SCANNER.offlineSyncUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce }, body: JSON.stringify({ tokens: chunk.map(function (item) { return item.token; }) }) });
				const data = await response.json().catch(function () { return {}; });
				if (!response.ok) throw new Error(data.message || t.syncFailed || 'Sync failed.');
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

	async function submitToken(token) {
		token = String(token || '').trim();
		if (locked || !token) return;
		unlockAudio();
		if (!navigator.onLine) { offline(token); return; }
		locked = true;
		show('busy', t.checking || '…');
		try {
			let response;
			try {
				const url = adm ? (mode === 'out' ? adm.checkoutUrl : adm.checkinUrl) : WPCAP_SCANNER.checkinUrl;
				const body = adm ? { token: token, area_id: areaId, idempotency_key: uuid() } : { token: token };
				response = await fetch(url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce },
					body: JSON.stringify(body)
				});
			} catch (networkError) {
				// The browser reported "online" but the request never reached the server.
				offline(token);
				return;
			}
			const data = await response.json().catch(function () { return {}; });
			if (!response.ok) throw new Error(data.message || t.invalid || 'Invalid ticket.');
			show('success', (data.status === 'checked_out' ? ((adm && adm.i18n && adm.i18n.checkedOut) || 'Checked out') + ': ' : '') + data.event + ' — ' + data.ticket);
			input.value = '';
		} catch (error) {
			show('error', error.message);
		} finally {
			window.setTimeout(function () { locked = false; }, 1400);
		}
	}

	form.addEventListener('submit', function (event) {
		event.preventDefault();
		submitToken(input.value);
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
						const match = raw.match(/[a-f0-9]{64}/i);
						const now = Date.now();
						// The same QR often stays in view after a scan: it is submitted again only after it was
						// out of view for 3 s (sliding window), so "already used" never overwrites the green result.
						if (match && match[0] === lastToken && now - lastTokenAt < 3000) {
							lastTokenAt = now;
						} else if (match) {
							lastToken = match[0];
							lastTokenAt = now;
							await submitToken(match[0]);
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
