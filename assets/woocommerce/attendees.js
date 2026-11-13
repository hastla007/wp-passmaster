/**
 * Pass Captain – participant fields in the WooCommerce checkout block.
 *
 * Renders one fieldset per ticket for ticket types with participant fields (cart extension data
 * `extensions.wpcap.attendee_types`), blocks "Place order" through the checkout validation store
 * while required answers are missing and sends the answers as checkout extension data
 * (`extensions.wpcap.attendees`, JSON). The server validates again.
 */
(function (wp, wc) {
	'use strict';
	if (!wp || !wp.element || !wp.data || !wc || !wc.blocksCheckout || !wc.blocksCheckout.registerCheckoutBlock) {
		return;
	}
	const h = wp.element.createElement;
	const useState = wp.element.useState;
	const useEffect = wp.element.useEffect;
	const useSelect = wp.data.useSelect;
	const settings = wc.wcSettings && wc.wcSettings.getSetting ? wc.wcSettings.getSetting('wpcap-woo-attendees_data', {}) : {};
	const t = settings.i18n || {};
	const CART = 'wc/store/cart';
	const CHECKOUT = 'wc/store/checkout';
	const VALIDATION = 'wc/store/validation';
	const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	function format(template, values) {
		let i = 0;
		return String(template || '').replace(/%(\d+\$)?[sd]/g, function (match, position) {
			const index = position ? parseInt(position, 10) - 1 : i++;
			return values[index] !== undefined ? String(values[index]) : '';
		});
	}

	function slots(types) {
		const list = [];
		let number = 0;
		types.forEach(function (type) {
			for (let i = 0; i < Number(type.quantity || 0); i++) {
				number++;
				list.push({ type: type, index: i, number: number });
			}
		});
		return list;
	}

	function errorsFor(types, values) {
		const errors = {};
		slots(types).forEach(function (slot) {
			const entry = ((values[slot.type.ticket_type_id] || [])[slot.index]) || {};
			const base = 'wpcap-att-' + slot.type.ticket_type_id + '-' + slot.index;
			if (entry.email && !EMAIL.test(entry.email)) {
				errors[base + '-email'] = t.invalidEmail || 'Invalid e-mail address.';
			}
			(slot.type.fields || []).forEach(function (field) {
				const value = (entry.answers || {})[field.key];
				const empty = field.type === 'checkbox' ? value !== '1' : !String(value || '').trim();
				if (field.required && empty) {
					errors[base + '-' + field.key] = format(t.required || 'Please fill in "%s".', [field.label]);
				} else if (field.type === 'email' && !empty && !EMAIL.test(String(value))) {
					errors[base + '-' + field.key] = t.invalidEmail || 'Invalid e-mail address.';
				}
			});
		});
		return errors;
	}

	function Field(props) {
		const id = props.id;
		const error = props.error && !props.error.hidden ? props.error.message : '';
		const common = {
			id: id,
			'aria-invalid': error ? 'true' : 'false',
			'aria-describedby': error ? id + '-error' : undefined,
			required: !!props.required,
		};
		let control;
		if (props.type === 'checkbox') {
			control = h('label', { htmlFor: id }, h('input', Object.assign({}, common, {
				type: 'checkbox',
				checked: props.value === '1',
				onChange: function (event) { props.onChange(event.target.checked ? '1' : ''); },
			})), h('span', null, props.label + (props.required ? ' *' : '')));
		} else {
			const tag = props.type === 'textarea' ? 'textarea' : 'input';
			const attrs = Object.assign({}, common, {
				value: props.value || '',
				onChange: function (event) { props.onChange(event.target.value); },
			});
			if (tag === 'input') {
				attrs.type = props.type === 'email' ? 'email' : 'text';
				attrs.autoComplete = 'off';
			}
			if (props.maxLength) attrs.maxLength = props.maxLength;
			control = [
				h('label', { htmlFor: id, key: 'l' }, props.label + (props.required ? ' *' : (props.optional ? ' ' + props.optional : ''))),
				h(tag, Object.assign({ key: 'c' }, attrs)),
			];
		}
		return h('div', { className: 'wpcap-woo-field wpcap-woo-field--' + (props.type || 'text') },
			control,
			props.description ? h('small', null, props.description) : null,
			error ? h('p', { className: 'wpcap-woo-error', id: id + '-error', role: 'alert' }, error) : null
		);
	}

	function AttendeeFields() {
		const types = useSelect(function (select) {
			const cart = select(CART).getCartData();
			return (cart && cart.extensions && cart.extensions.wpcap && cart.extensions.wpcap.attendee_types) || [];
		}, []);
		const [values, setValues] = useState({});
		const ids = slots(types).reduce(function (all, slot) {
			const base = 'wpcap-att-' + slot.type.ticket_type_id + '-' + slot.index;
			all.push(base + '-email');
			(slot.type.fields || []).forEach(function (field) { all.push(base + '-' + field.key); });
			return all;
		}, []);
		const validation = useSelect(function (select) {
			const store = select(VALIDATION);
			const out = {};
			ids.forEach(function (id) { out[id] = store.getValidationError(id); });
			return out;
		}, [ids.join('|')]);

		useEffect(function () {
			wp.data.dispatch(CHECKOUT).setExtensionData('wpcap', { attendees: JSON.stringify(values) });
			const errors = errorsFor(types, values);
			const store = wp.data.dispatch(VALIDATION);
			const current = wp.data.select(VALIDATION);
			const set = {};
			const clear = [];
			ids.forEach(function (id) {
				if (errors[id]) {
					const existing = current.getValidationError(id);
					set[id] = { message: errors[id], hidden: existing ? existing.hidden : true };
				} else {
					clear.push(id);
				}
			});
			if (Object.keys(set).length) store.setValidationErrors(set);
			if (clear.length) store.clearValidationErrors(clear);
		}, [values, JSON.stringify(types)]);

		useEffect(function () {
			return function () { wp.data.dispatch(VALIDATION).clearValidationErrors(ids); };
		}, [ids.join('|')]);

		if (!types.length) return null;

		function update(typeId, index, key, value) {
			setValues(function (previous) {
				const next = Object.assign({}, previous);
				const list = (next[typeId] || []).slice();
				const entry = Object.assign({ answers: {} }, list[index] || {});
				if (key === '__name') entry.name = value;
				else if (key === '__email') entry.email = value;
				else entry.answers = Object.assign({}, entry.answers, { [key]: value });
				list[index] = entry;
				next[typeId] = list;
				return next;
			});
		}

		return h('div', { className: 'wpcap-woo-attendees', id: 'wpcap-woo-attendees' },
			h('h2', { className: 'wc-block-components-title wc-block-components-checkout-step__title' }, t.title || 'Attendees'),
			slots(types).map(function (slot) {
				const typeId = slot.type.ticket_type_id;
				const entry = ((values[typeId] || [])[slot.index]) || {};
				const base = 'wpcap-att-' + typeId + '-' + slot.index;
				return h('fieldset', { className: 'wpcap-woo-attendee', key: base },
					h('legend', null, format(t.ticket || 'Ticket %1$d · %2$s', [slot.number, slot.type.label])),
					h(Field, { id: base + '-name', label: t.name || 'Name', optional: t.optional, value: entry.name, maxLength: 190, onChange: function (v) { update(typeId, slot.index, '__name', v); } }),
					h(Field, { id: base + '-email', type: 'email', label: t.email || 'E-mail', optional: t.optional, value: entry.email, error: validation[base + '-email'], onChange: function (v) { update(typeId, slot.index, '__email', v); } }),
					(slot.type.fields || []).map(function (field) {
						const id = base + '-' + field.key;
						return h(Field, {
							key: id,
							id: id,
							type: field.type,
							label: field.label,
							required: field.required,
							description: field.purpose,
							value: (entry.answers || {})[field.key],
							maxLength: field.type === 'textarea' ? undefined : 2000,
							error: validation[id],
							onChange: function (v) { update(typeId, slot.index, field.key, v); },
						});
					})
				);
			})
		);
	}

	wc.blocksCheckout.registerCheckoutBlock({
		metadata: {
			name: 'pass-captain/woo-attendees',
			parent: ['woocommerce/checkout-contact-information-block'],
		},
		component: AttendeeFields,
		force: true,
	});
})(window.wp, window.wc);
