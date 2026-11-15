(function () {
	'use strict';
	const root = document.querySelector('.wpcap-scanner');
	if (!root || !window.WPCAP_SCANNER) return;
	const form = root.querySelector('.wpcap-scan-form');
	const input = form.querySelector('[name="token"]');
	const result = root.querySelector('.wpcap-scan-result');
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
			radio.addEventListener('change', function () { if (radio.checked) { mode = value; root.dataset.mode = value; } });
			label.appendChild(radio);
			label.appendChild(document.createTextNode(' ' + (value === 'in' ? (at.modeIn || 'In') : (at.modeOut || 'Out')) + ' '));
			bar.appendChild(label);
		});
		root.insertBefore(bar, form);
	}
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
	// key: idempotency key of the online attempt that did not get an answer (timeout, network). The
	// queued scan reuses it, so an attempt that did reach the server is replayed at sync instead of
	// becoming an "already used" conflict.
	function enqueue(token, key) {
		const items = queue();
		items.push({ token: token, idempotency_key: key || uuid(), scanned_at: new Date().toISOString(), stored_at: Date.now(), area_id: areaId, direction: mode });
		saveQueue(items);
		result.textContent = t.queued || 'Saved offline.';
		result.dataset.state = 'warning';
		input.value = '';
		updateQueueButton();
	}
	/* ---- feedback: tones (WebAudio, can be switched off per browser), torch ---- */
	let soundOn = prefs.sound !== false;
	let audio = null;
	/** Short tone per result: high beep = admitted, double beep = warning, low buzz = rejected. */
	function beep(kind) {
		if (!soundOn) return;
		const Ctx = window.AudioContext || window.webkitAudioContext;
		if (!Ctx) return;
		try {
			audio = audio || new Ctx();
			if (audio.state === 'suspended' && audio.resume) audio.resume();
			const tones = kind === 'success' ? [[880, 0, 0.12]] : kind === 'warning' ? [[660, 0, 0.1], [660, 0.16, 0.1]] : [[220, 0, 0.35]];
			tones.forEach(function (tone) {
				const osc = audio.createOscillator();
				const gain = audio.createGain();
				osc.type = kind === 'error' ? 'square' : 'sine';
				osc.frequency.value = tone[0];
				gain.gain.value = 0.15;
				osc.connect(gain);
				gain.connect(audio.destination);
				const at = (audio.currentTime || 0) + tone[1];
				osc.start(at);
				osc.stop(at + tone[2]);
			});
		} catch (ignore) {}
	}
	/** Sets the result text and state (pending, success, warning, error) with tone and vibration. */
	function show(text, state) {
		result.textContent = text;
		result.dataset.state = state;
		if (state === 'pending') return;
		beep(state);
		if (navigator.vibrate) { try { navigator.vibrate(state === 'success' ? 80 : [60, 60, 60]); } catch (ignore) {} }
	}
	const soundButton = document.createElement('button');
	soundButton.type = 'button';
	soundButton.className = 'button wpcap-sound-toggle';
	function renderSound() {
		soundButton.textContent = soundOn ? (t.soundOn || 'Sound on') : (t.soundOff || 'Sound off');
		soundButton.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
	}
	soundButton.addEventListener('click', function () { soundOn = !soundOn; prefs.sound = soundOn; savePrefs(); renderSound(); });
	renderSound();
	const torchButton = document.createElement('button');
	torchButton.type = 'button';
	torchButton.className = 'button wpcap-torch-toggle';
	torchButton.textContent = t.torch || 'Torch';
	torchButton.hidden = true;
	torchButton.setAttribute('aria-pressed', 'false');
	let torchOn = false;
	let videoTrack = null;
	/** Torch switch and continuous autofocus where the camera supports them (Chrome/Android). */
	function setupTrack(current) {
		const track = current && current.getVideoTracks ? current.getVideoTracks()[0] : null;
		let caps = {};
		try { caps = (track && track.getCapabilities && track.getCapabilities()) || {}; } catch (ignore) { caps = {}; }
		videoTrack = track;
		torchOn = false;
		torchButton.setAttribute('aria-pressed', 'false');
		torchButton.hidden = !caps.torch;
		if (track && Array.isArray(caps.focusMode) && caps.focusMode.indexOf('continuous') !== -1 && track.applyConstraints) {
			track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});
		}
	}
	torchButton.addEventListener('click', function () {
		const track = videoTrack;
		if (!track || !track.applyConstraints) return;
		const next = !torchOn;
		track.applyConstraints({ advanced: [{ torch: next }] }).then(function () { torchOn = next; torchButton.setAttribute('aria-pressed', next ? 'true' : 'false'); }).catch(function () {});
	});
	// Offline the code cannot be checked: never shown as admitted. Only codes that look like a ticket are
	// offered, and only after an explicit staff decision ("admit after a visual check") are they queued
	// for the later server check. Reliable offline admission: the offline scanner app with a ticket package.
	// Signed QR payload (PC1.<claims>.<signature>, setting "signed_qr"); verified by the server.
	var SIGNED_QR = /PC1\.[A-Za-z0-9_-]{16,700}\.[A-Za-z0-9_-]{86}/;
	function offline(token, key) {
		if (!/(^|[^a-f0-9])[a-f0-9]{64}($|[^a-f0-9])/i.test(token) && !SIGNED_QR.test(token)) {
			show(t.invalid || 'Invalid ticket.', 'error');
			return;
		}
		show(t.unchecked || 'NOT CHECKED (offline).', 'warning');
		const admit = document.createElement('button');
		admit.type = 'button';
		admit.className = 'button wpcap-admit-unchecked';
		admit.textContent = t.admitUnchecked || 'Admit after visual check';
		admit.addEventListener('click', function () { enqueue(token, key); input.focus(); });
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
			result.textContent = ((privacy.i18n && privacy.i18n.expired) || (adm && adm.i18n && adm.i18n.expired) || t.expired || '%d offline scan(s) older than 24 hours were discarded without upload.').replace('%d', items.length - fresh.length);
			result.dataset.state = 'error';
		}
	}
	const syncButton = document.createElement('button');
	syncButton.type = 'button';
	syncButton.textContent = t.syncButton || 'Sync offline scans';
	syncButton.hidden = true;
	root.appendChild(syncButton);
	const feedbackBar = document.createElement('div');
	feedbackBar.className = 'wpcap-scanner-feedback';
	feedbackBar.appendChild(soundButton);
	feedbackBar.appendChild(torchButton);
	root.insertBefore(feedbackBar, form);
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
			result.textContent = (t.synced || '%1$d / %2$d').replace('%1$d', sent).replace('%2$d', conflicts);
			result.dataset.state = conflicts ? 'error' : 'success';
		} catch (error) { result.textContent = error.message; result.dataset.state = 'error'; }
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

	/** Request timeout of a scan: afterwards the scan counts as offline (visual check, queued with the same key). */
	const SCAN_TIMEOUT_MS = 5000;
	async function submitToken(token) {
		token = String(token || '').trim();
		if (locked || !token) return;
		// One key per scan: the online attempt and a later offline upload of the same scan share it.
		const key = uuid();
		if (!navigator.onLine) { offline(token, key); return; }
		locked = true;
		show(t.checking || '…', 'pending');
		try {
			let response;
			const ctrl = typeof AbortController === 'function' ? new AbortController() : null;
			const timer = ctrl ? window.setTimeout(function () { ctrl.abort(); }, SCAN_TIMEOUT_MS) : 0;
			try {
				const url = adm ? (mode === 'out' ? adm.checkoutUrl : adm.checkinUrl) : WPCAP_SCANNER.checkinUrl;
				const body = adm ? { token: token, area_id: areaId, idempotency_key: key } : { token: token };
				response = await fetch(url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce },
					body: JSON.stringify(body),
					signal: ctrl ? ctrl.signal : undefined
				});
			} catch (networkError) {
				// The browser reported "online" but the request never reached the server or timed out.
				offline(token, key);
				return;
			} finally {
				if (timer) window.clearTimeout(timer);
			}
			const data = await response.json().catch(function () { return {}; });
			if (!response.ok) throw new Error(data.message || t.invalid || 'Invalid ticket.');
			// The attendee name comes only when the operator enabled scanner_show_attendee.
			const name = data.attendee_name ? ' · ' + data.attendee_name : '';
			show((data.status === 'checked_out' ? '↩ ' + ((adm && adm.i18n && adm.i18n.checkedOut) || 'Checked out') + ': ' : '✓ ') + data.event + ' — ' + data.ticket + name, 'success');
			input.value = '';
		} catch (error) {
			show(error.message, 'error');
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

	const cameraButton = root.querySelector('.wpcap-camera-start');
	/**
	 * Stops the camera: every track of the stream is ended (the browser's camera indicator goes off),
	 * the video is detached and hidden and the start button is usable again. Runs when the page is
	 * hidden or left (pagehide, visibilitychange) and after camera errors (audit FE-6).
	 */
	function stopCamera() {
		const current = stream;
		stream = null;
		if (current && current.getTracks) current.getTracks().forEach(function (track) { try { track.stop(); } catch (ignore) {} });
		if (video) {
			try { if (video.pause) video.pause(); } catch (ignore) {}
			video.srcObject = null;
			video.hidden = true;
		}
		if (cameraButton) cameraButton.disabled = false;
		torchButton.hidden = true;
		videoTrack = null;
	}
	window.addEventListener('pagehide', stopCamera);
	document.addEventListener('visibilitychange', function () { if ('hidden' === document.visibilityState) stopCamera(); });

	cameraButton.addEventListener('click', async function (event) {
		const button = event.currentTarget;
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			result.textContent = t.noCamera || 'Camera scanning is not supported.';
			return;
		}
		try {
			const own = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
			if (stream) stopCamera(); // a second start while one is running: keep only the newest stream
			stream = own;
			video.srcObject = stream;
			video.setAttribute('playsinline', '');
			video.muted = true;
			video.hidden = false;
			await video.play();
			setupTrack(own);
			const detect = await makeDetector();
			// Stopped meanwhile (page hidden or left): do not restart the scan loop.
			if (stream !== own) return;
			const hadFocus = document.activeElement === button;
			button.disabled = true;
			// A disabled button loses keyboard focus; keep the manual input as the next stop.
			if (hadFocus) input.focus();
			let busy = false;
			const scan = async function () {
				if (!stream) return;
				if (!busy && !locked) {
					busy = true;
					try {
						const raw = await detect();
						const match = raw.match(SIGNED_QR) || raw.match(/[a-f0-9]{64}/i);
						const now = Date.now();
						// The same QR stays in view after a scan; do not re-submit it immediately.
						if (match && (match[0] !== lastToken || now - lastTokenAt > 5000)) {
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
			// A stream obtained before the failure (e.g. QR decoder could not load) must not keep the camera on.
			stopCamera();
			result.textContent = (t.cameraError || 'Camera error:') + ' ' + error.message;
		}
	});
	// Test hook for tests/js (node:test); `module` does not exist in the browser, so this is a no-op there.
	if (typeof module === 'object' && module && module.exports) { module.exports = { queueKey: queueKey, queue: queue, normalizeItem: normalizeItem, enqueue: enqueue, offline: offline, dropExpired: dropExpired, syncQueue: syncQueue, submitToken: submitToken, beep: beep }; }
})();
