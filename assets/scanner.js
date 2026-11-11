(function () {
	'use strict';
	const root = document.querySelector('.wpcap-scanner');
	if (!root || !window.WPCAP_SCANNER) return;
	const form = root.querySelector('.wpcap-scan-form');
	const input = form.querySelector('[name="token"]');
	const result = root.querySelector('.wpcap-scan-result');
	const video = root.querySelector('.wpcap-camera');
	const queueKey = 'wpcap-offline-scans-v1';
	let stream = null;
	let locked = false;
	function queue() {
		try { const stored = JSON.parse(window.localStorage.getItem(queueKey) || '[]'); return Array.isArray(stored) ? stored : []; } catch (ignore) { return []; }
	}
	function saveQueue(items) { try { window.localStorage.setItem(queueKey, JSON.stringify(items)); } catch (ignore) {} }
	const syncButton = document.createElement('button');
	syncButton.type = 'button';
	syncButton.textContent = 'Offline-Scans synchronisieren';
	syncButton.hidden = true;
	root.appendChild(syncButton);
	function updateQueueButton() { const count = queue().length; syncButton.hidden = !count; syncButton.textContent = count + ' Offline-Scan(s) synchronisieren'; }
	async function syncQueue() {
		const items = queue();
		if (!items.length || !navigator.onLine) { updateQueueButton(); return; }
		syncButton.disabled = true;
		try {
			const response = await fetch(WPCAP_SCANNER.offlineSyncUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce }, body: JSON.stringify({ tokens: items }) });
			const data = await response.json();
			if (!response.ok) throw new Error(data.message || 'Synchronisierung fehlgeschlagen.');
			saveQueue([]);
			const conflicts = (data.results || []).filter(function (item) { return item.status === 'conflict'; }).length;
			result.textContent = items.length + ' Offline-Scan(s) synchronisiert; Konflikte: ' + conflicts + '.';
			result.dataset.state = conflicts ? 'error' : 'success';
		} catch (error) { result.textContent = error.message; result.dataset.state = 'error'; }
		finally { syncButton.disabled = false; updateQueueButton(); }
	}
	syncButton.addEventListener('click', syncQueue);
	window.addEventListener('online', syncQueue);
	updateQueueButton();

	async function submitToken(token) {
		if (locked || !token) return;
		if (!navigator.onLine) {
			const items = queue();
			items.push(token.trim());
			saveQueue(items.slice(-100));
			result.textContent = 'Offline gespeichert. Der Einlass wird erst nach der Synchronisierung serverseitig bestätigt.';
			result.dataset.state = 'error';
			input.value = '';
			updateQueueButton();
			return;
		}
		locked = true;
		result.textContent = 'Ticket wird geprüft …';
		try {
			const response = await fetch(WPCAP_SCANNER.checkinUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WPCAP_SCANNER.nonce },
				body: JSON.stringify({ token: token.trim() })
			});
			const data = await response.json();
			if (!response.ok) throw new Error(data.message || 'Ticket nicht gültig.');
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

	root.querySelector('.wpcap-camera-start').addEventListener('click', async function (event) {
		if (!('BarcodeDetector' in window) || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			result.textContent = 'Kamera-QR-Scan wird von diesem Browser nicht unterstützt. Nutze die manuelle Eingabe.';
			return;
		}
		try {
			stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
			video.srcObject = stream;
			video.hidden = false;
			await video.play();
			const detector = new BarcodeDetector({ formats: ['qr_code'] });
			event.currentTarget.disabled = true;
			const scan = async function () {
				if (!stream) return;
				try {
					const codes = await detector.detect(video);
					if (codes.length && !locked) {
						const match = String(codes[0].rawValue || '').match(/[a-f0-9]{64}/i);
						if (match) await submitToken(match[0]);
					}
				} catch (ignore) {}
				window.requestAnimationFrame(scan);
			};
			scan();
		} catch (error) {
			result.textContent = 'Kamerazugriff nicht möglich: ' + error.message;
		}
	});
})();
