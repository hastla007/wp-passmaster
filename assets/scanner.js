(function () {
	'use strict';
	const root = document.querySelector('.wpcap-scanner');
	if (!root || !window.WPCAP_SCANNER) return;
	const form = root.querySelector('.wpcap-scan-form');
	const input = form.querySelector('[name="token"]');
	const result = root.querySelector('.wpcap-scan-result');
	const video = root.querySelector('.wpcap-camera');
	let stream = null;
	let locked = false;

	async function submitToken(token) {
		if (locked || !token) return;
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
