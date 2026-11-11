(function () {
	'use strict';
	const root = document.querySelector('.wpcap-scanner');
	if (!root || !window.WPCAP_SCANNER) return;
	const form = root.querySelector('.wpcap-scan-form');
	const input = form.querySelector('[name="token"]');
	const result = root.querySelector('.wpcap-scan-result');
	const video = root.querySelector('.wpcap-camera');
	const queueKey = 'wpcap-offline-scans-v1';
	const t = window.WPCAP_SCANNER.i18n || {};
	let stream = null;
	let locked = false;
	let lastToken = '';
	let lastTokenAt = 0;
	function enqueue(token) {
		const items = queue();
		items.push(token);
		saveQueue(items.slice(-100));
		result.textContent = t.queued || 'Saved offline.';
		result.dataset.state = 'error';
		input.value = '';
		updateQueueButton();
	}
	function queue() {
		try { const stored = JSON.parse(window.localStorage.getItem(queueKey) || '[]'); return Array.isArray(stored) ? stored : []; } catch (ignore) { return []; }
	}
	function saveQueue(items) { try { window.localStorage.setItem(queueKey, JSON.stringify(items)); } catch (ignore) {} }
	const syncButton = document.createElement('button');
	syncButton.type = 'button';
	syncButton.textContent = t.syncButton || 'Sync offline scans';
	syncButton.hidden = true;
	root.appendChild(syncButton);
	function updateQueueButton() { const count = queue().length; syncButton.hidden = !count; syncButton.textContent = (t.syncCount || '%d').replace('%d', count); }
	async function syncQueue() {
		const items = queue();
		if (!items.length || !navigator.onLine) { updateQueueButton(); return; }
		syncButton.disabled = true;
		try {
			const response = await fetch(WPCAP_SCANNER.offlineSyncUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce }, body: JSON.stringify({ tokens: items }) });
			const data = await response.json();
			if (!response.ok) throw new Error(data.message || t.syncFailed || 'Sync failed.');
			// Keep scans queued while this request was in flight.
			saveQueue(queue().slice(items.length));
			const conflicts = (data.results || []).filter(function (item) { return item.status === 'conflict'; }).length;
			result.textContent = (t.synced || '%1$d / %2$d').replace('%1$d', items.length).replace('%2$d', conflicts);
			result.dataset.state = conflicts ? 'error' : 'success';
		} catch (error) { result.textContent = error.message; result.dataset.state = 'error'; }
		finally { syncButton.disabled = false; updateQueueButton(); }
	}
	syncButton.addEventListener('click', syncQueue);
	window.addEventListener('online', syncQueue);
	updateQueueButton();

	async function submitToken(token) {
		token = String(token || '').trim();
		if (locked || !token) return;
		if (!navigator.onLine) { enqueue(token); return; }
		locked = true;
		result.textContent = t.checking || '…';
		try {
			let response;
			try {
				response = await fetch(WPCAP_SCANNER.checkinUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce },
					body: JSON.stringify({ token: token })
				});
			} catch (networkError) {
				// The browser reported "online" but the request never reached the server.
				enqueue(token);
				return;
			}
			const data = await response.json().catch(function () { return {}; });
			if (!response.ok) throw new Error(data.message || t.invalid || 'Invalid ticket.');
			result.textContent = '✓ ' + data.event + ' — ' + data.ticket;
			result.dataset.state = 'success';
			input.value = '';
		} catch (error) {
			result.textContent = error.message;
			result.dataset.state = 'error';
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

	root.querySelector('.wpcap-camera-start').addEventListener('click', async function (event) {
		const button = event.currentTarget;
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			result.textContent = t.noCamera || 'Camera scanning is not supported.';
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
			button.disabled = true;
			let busy = false;
			const scan = async function () {
				if (!stream) return;
				if (!busy && !locked) {
					busy = true;
					try {
						const raw = await detect();
						const match = raw.match(/[a-f0-9]{64}/i);
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
			result.textContent = (t.cameraError || 'Camera error:') + ' ' + error.message;
		}
	});
})();
