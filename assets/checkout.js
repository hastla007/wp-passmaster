(function () {
	'use strict';
	document.addEventListener('submit', async function (event) {
		const form = event.target.closest('.wpcap-checkout-form');
		if (!form) return;
		event.preventDefault();
		const button = form.querySelector('button[type="submit"]');
		const message = form.querySelector('.wpcap-message');
		const select = form.querySelector('[name="ticket_type_id"]');
		const quantity = Number(form.querySelector('[name="quantity"]').value);
		const max = Number(select.selectedOptions[0].dataset.max || 10);
		if (quantity < 1 || quantity > max) {
			message.textContent = 'Bitte wähle eine gültige Ticketanzahl.';
			return;
		}
		button.disabled = true;
		message.textContent = 'Bestellung wird angelegt …';
		try {
			const response = await fetch(window.WPCAP_CHECKOUT.url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					email: form.querySelector('[name="email"]').value,
					items: [{ ticket_type_id: Number(select.value), quantity: quantity }],
					idempotency_key: (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2)
				})
			});
			const data = await response.json();
			if (!response.ok) throw new Error(data.message || 'Bestellung fehlgeschlagen.');
			message.textContent = data.status === 'complete'
				? 'Bestellung abgeschlossen. Die Tickets wurden an deine E-Mail-Adresse gesendet.'
				: 'Bestellung angelegt und ' + data.hold_minutes + ' Minuten reserviert. Sie wartet auf manuelle Zahlungsbestätigung durch den Veranstalter.';
			form.reset();
		} catch (error) {
			message.textContent = error.message;
		} finally {
			button.disabled = false;
		}
	});
})();
