/* Pass Captain – accessible seat picker for the checkout form (SVG + keyboard + list fallback). */
(function () {
	'use strict';
	const SVGNS = 'http://www.w3.org/2000/svg';
	const R = 10;

	function parse(text, fallback) { try { return JSON.parse(text || '') || fallback; } catch (e) { return fallback; } }
	function h(tag, attrs, parent, text) {
		const node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { if (k === 'className') node.className = attrs[k]; else node.setAttribute(k, attrs[k]); });
		if (text !== undefined) node.textContent = text;
		if (parent) parent.appendChild(node);
		return node;
	}
	function s(tag, attrs, parent) {
		const node = document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
		if (parent) parent.appendChild(node);
		return node;
	}
	function format(text) { const args = Array.prototype.slice.call(arguments, 1); let i = 0; return String(text).replace(/%(\d\$)?[sd]/g, function (m, pos) { return pos ? args[parseInt(pos, 10) - 1] : args[i++]; }); }

	function Picker(box) {
		this.box = box;
		this.form = box.closest('form');
		this.t = parse(box.dataset.i18n, {});
		this.url = box.dataset.url;
		this.status = box.querySelector('.wpcap-seating-status');
		this.data = null;
		this.selected = new Map(); // seat id => ticket type id
		this.focusId = null;
		this.load(true);
		const self = this;
		this.timer = window.setInterval(function () { if (!document.hidden) self.load(false); }, 30000);
		this.form.addEventListener('change', function (evt) { if (evt.target.classList.contains('wpcap-qty')) { self.reassign(); self.renderSelection(); } });
		this.form.addEventListener('reset', function () { window.setTimeout(function () { self.selected.clear(); self.load(false); }, 0); });
		document.addEventListener('wpcap:checkout-payload', function (evt) { if (evt.detail && evt.detail.form === self.form) self.payload(evt.detail); });
	}

	Picker.prototype.say = function (text) { this.status.textContent = text; };

	Picker.prototype.load = function (first) {
		const self = this;
		if (first) this.say(this.t.loading || '…');
		fetch(this.url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
			if (!r.ok) throw new Error('http');
			return r.json();
		}).then(function (data) {
			self.data = data;
			self.byId = new Map(data.seats.map(function (seat) { return [seat.id, seat]; }));
			let lost = false;
			self.selected.forEach(function (type, id) { const seat = self.byId.get(id); if (!seat || seat.status !== 'free') { self.selected.delete(id); lost = true; } });
			if (first) self.build(); else self.update();
			self.say(lost ? self.t.seatTaken : (first ? self.t.help || '' : self.status.textContent));
		}).catch(function () { if (first) self.say(self.t.loadFailed || 'Error'); });
	};

	Picker.prototype.quantities = function () {
		const out = {};
		this.form.querySelectorAll('.wpcap-qty').forEach(function (input) { out[input.dataset.type] = { qty: Number(input.value) || 0, name: input.dataset.name || ('#' + input.dataset.type) }; });
		return out;
	};

	Picker.prototype.eligibleTypes = function (seat) {
		const types = this.data.types || {};
		return Object.keys(types).filter(function (id) { return types[id].indexOf(seat.zone) !== -1 || types[id].indexOf(seat.category) !== -1; });
	};

	Picker.prototype.pickType = function (seat) {
		const q = this.quantities();
		const eligible = this.eligibleTypes(seat);
		const used = {};
		this.selected.forEach(function (type) { used[type] = (used[type] || 0) + 1; });
		const open = eligible.filter(function (id) { return q[id] && q[id].qty > (used[id] || 0); });
		if (open.length) return open[0];
		const ordered = eligible.filter(function (id) { return q[id] && q[id].qty > 0; });
		return ordered[0] || eligible[0] || null;
	};

	Picker.prototype.reassign = function () {
		const self = this;
		const q = this.quantities();
		const stale = [];
		this.selected.forEach(function (type, id) { if (!q[type] || !q[type].qty) stale.push(id); });
		stale.forEach(function (id) { self.selected.delete(id); });
		stale.forEach(function (id) { const type = self.pickType(self.byId.get(id)); if (type) self.selected.set(id, type); });
	};

	Picker.prototype.label = function (seat) {
		const t = this.t;
		const zone = (this.data.zones.find(function (z) { return z.key === seat.zone; }) || {}).name;
		const cat = (this.data.categories.find(function (c) { return c.key === seat.category; }) || {}).name;
		const state = this.selected.has(seat.id) ? t.selected : (seat.status === 'free' ? t.free : t.taken);
		return [seat.label, zone, cat, seat.accessible ? t.accessible : '', state].filter(Boolean).join(', ');
	};

	Picker.prototype.toggle = function (id) {
		const seat = this.byId.get(id);
		if (!seat) return;
		if (this.selected.has(id)) {
			this.selected.delete(id);
			this.say(format(this.t.removed, seat.label));
		} else {
			if (seat.status !== 'free') return;
			const type = this.pickType(seat);
			if (!type) { this.say(this.t.noType); return; }
			this.selected.set(id, type);
			this.say(format(this.t.added, seat.label));
		}
		this.update();
	};

	Picker.prototype.addStanding = function (areaKey, count) {
		const self = this;
		const spots = this.data.seats.filter(function (seat) { return seat.kind === 'standing' && seat.group === areaKey; });
		const chosen = spots.filter(function (seat) { return self.selected.has(seat.id); });
		if (count < chosen.length) {
			chosen.slice(count).forEach(function (seat) { self.selected.delete(seat.id); });
		} else {
			spots.filter(function (seat) { return seat.status === 'free' && !self.selected.has(seat.id); }).slice(0, count - chosen.length).forEach(function (seat) {
				const type = self.pickType(seat);
				if (type) self.selected.set(seat.id, type);
			});
		}
		this.update();
	};

	Picker.prototype.build = function () {
		const self = this;
		const d = this.data;
		const colors = {};
		d.categories.forEach(function (c) { colors[c.key] = c.color; });
		const legend = h('ul', { className: 'wpcap-seating-legend', 'aria-label': this.t.legend || '' }, this.box);
		d.categories.forEach(function (c) { const li = h('li', {}, legend); h('span', { className: 'wpcap-seating-swatch', style: 'background:' + c.color, 'aria-hidden': 'true' }, li); li.appendChild(document.createTextNode(c.name)); });
		h('li', {}, legend, '♿ ' + (this.t.accessible || ''));

		const wrap = h('div', { className: 'wpcap-seating-map' }, this.box);
		const svg = s('svg', { viewBox: '0 0 ' + d.width + ' ' + d.height, role: 'group', 'aria-label': this.t.title || '' }, wrap);
		this.svg = svg;
		s('text', { x: d.width / 2, y: 16, 'text-anchor': 'middle', class: 'wpcap-seating-stage', 'aria-hidden': 'true' }, svg).textContent = '▭';
		d.areas.forEach(function (area) {
			const rect = s('rect', { x: area.x, y: area.y, width: area.w, height: area.h, rx: 6, fill: colors[area.category] || '#8c8f94', class: 'wpcap-seating-area', 'aria-hidden': 'true' }, svg);
			rect.style.fillOpacity = 0.25;
			s('text', { x: area.x + area.w / 2, y: area.y + area.h / 2, 'text-anchor': 'middle', 'aria-hidden': 'true' }, svg).textContent = area.label;
		});
		this.nodes = new Map();
		const seated = d.seats.filter(function (seat) { return seat.kind === 'seat'; });
		seated.forEach(function (seat) {
			const g = s('g', { class: 'wpcap-seat', role: 'checkbox', tabindex: '-1', 'data-id': seat.id }, svg);
			s('circle', { cx: seat.x, cy: seat.y, r: R, fill: colors[seat.category] || '#8c8f94' }, g);
			if (seat.accessible) s('text', { x: seat.x, y: seat.y + 4, 'text-anchor': 'middle', 'aria-hidden': 'true', class: 'wpcap-seat-icon' }, g).textContent = '♿';
			g.addEventListener('click', function () { self.focusId = seat.id; self.toggle(seat.id); });
			self.nodes.set(seat.id, g);
		});
		if (seated.length) this.focusId = (seated.find(function (seat) { return seat.status === 'free'; }) || seated[0]).id;
		svg.addEventListener('keydown', function (evt) { self.key(evt, seated); });

		// List fallback: real checkboxes, grouped by zone and row.
		const details = h('details', { className: 'wpcap-seating-list' }, this.box);
		h('summary', {}, details, this.t.listToggle || '');
		this.listBox = details;
		const groups = {};
		seated.forEach(function (seat) {
			const zone = (d.zones.find(function (z) { return z.key === seat.zone; }) || { name: '' }).name;
			const key = zone + '|' + seat.row;
			(groups[key] = groups[key] || { title: [zone, seat.row].filter(Boolean).join(' – '), seats: [] }).seats.push(seat);
		});
		this.checks = new Map();
		Object.keys(groups).forEach(function (key) {
			const fs = h('fieldset', {}, details);
			h('legend', {}, fs, groups[key].title);
			groups[key].seats.forEach(function (seat) {
				const label = h('label', {}, fs);
				const box = h('input', { type: 'checkbox', value: String(seat.id) }, label);
				label.appendChild(document.createTextNode(' ' + seat.seat + (seat.accessible ? ' ♿' : '')));
				box.addEventListener('change', function () { self.toggle(seat.id); });
				self.checks.set(seat.id, box);
			});
		});
		this.areaInputs = new Map();
		d.areas.forEach(function (area) {
			const label = h('label', { className: 'wpcap-seating-standing' }, self.box, area.label + ' – ' + (self.t.standing || '') + ' ');
			const input = h('input', { type: 'number', min: '0', value: '0', inputmode: 'numeric' }, label);
			input.addEventListener('change', function () { self.addStanding(area.key, Math.max(0, Number(input.value) || 0)); });
			self.areaInputs.set(area.key, input);
		});

		const chosen = h('div', { className: 'wpcap-seating-selection' }, this.box);
		h('h3', {}, chosen, this.t.yourSeats || '');
		this.selectionList = h('ul', {}, chosen);
		this.update();
	};

	Picker.prototype.key = function (evt, seated) {
		const current = this.byId.get(this.focusId);
		if (!current) return;
		if (evt.key === ' ' || evt.key === 'Enter') { evt.preventDefault(); this.toggle(current.id); return; }
		const dirs = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
		let target = null;
		if (dirs[evt.key]) {
			const dir = dirs[evt.key];
			let best = Infinity;
			seated.forEach(function (seat) {
				const dx = seat.x - current.x, dy = seat.y - current.y;
				const along = dx * dir[0] + dy * dir[1];
				if (along <= 0) return;
				const across = Math.abs(dx * dir[1] - dy * dir[0]);
				const score = along + across * 3;
				if (score < best) { best = score; target = seat; }
			});
		} else if (evt.key === 'Home') { target = seated[0]; } else if (evt.key === 'End') { target = seated[seated.length - 1]; }
		if (target) { evt.preventDefault(); this.focusId = target.id; this.update(); this.nodes.get(target.id).focus(); }
	};

	Picker.prototype.update = function () {
		const self = this;
		if (!this.nodes) return;
		this.nodes.forEach(function (node, id) {
			const seat = self.byId.get(id);
			const selected = self.selected.has(id);
			node.setAttribute('aria-checked', selected ? 'true' : 'false');
			node.setAttribute('aria-disabled', seat.status !== 'free' && !selected ? 'true' : 'false');
			node.setAttribute('aria-label', self.label(seat));
			node.setAttribute('tabindex', id === self.focusId ? '0' : '-1');
			node.setAttribute('class', 'wpcap-seat' + (selected ? ' is-selected' : '') + (seat.status !== 'free' ? ' is-taken' : ''));
			const box = self.checks.get(id);
			if (box) { box.checked = selected; box.disabled = seat.status !== 'free' && !selected; }
		});
		this.areaInputs.forEach(function (input, key) {
			const spots = self.data.seats.filter(function (seat) { return seat.kind === 'standing' && seat.group === key; });
			input.max = String(spots.filter(function (seat) { return seat.status === 'free' || self.selected.has(seat.id); }).length);
			input.value = String(spots.filter(function (seat) { return self.selected.has(seat.id); }).length);
		});
		this.renderSelection();
	};

	Picker.prototype.renderSelection = function () {
		const self = this;
		if (!this.selectionList) return;
		this.selectionList.textContent = '';
		if (!this.selected.size) { h('li', {}, this.selectionList, this.t.none || ''); return; }
		const q = this.quantities();
		this.selected.forEach(function (type, id) {
			const seat = self.byId.get(id);
			const li = h('li', {}, self.selectionList, seat.label + ' ');
			const select = h('select', { 'aria-label': (self.t.ticketType || '') + ': ' + seat.label }, li);
			self.eligibleTypes(seat).forEach(function (typeId) {
				const option = h('option', { value: typeId }, select, q[typeId] ? q[typeId].name : '#' + typeId);
				if (String(typeId) === String(type)) option.selected = true;
			});
			select.addEventListener('change', function () { self.selected.set(id, select.value); });
			const remove = h('button', { type: 'button', className: 'wpcap-seating-remove' }, li, self.t.remove || 'x');
			remove.addEventListener('click', function () { self.toggle(id); });
		});
	};

	Picker.prototype.payload = function (detail) {
		if (!this.data) return;
		const q = this.quantities();
		const types = this.data.types || {};
		const seats = {};
		this.selected.forEach(function (type, id) { (seats[type] = seats[type] || []).push(id); });
		for (const typeId of Object.keys(types)) {
			const want = q[typeId] ? q[typeId].qty : 0;
			const have = (seats[typeId] || []).length;
			if (want !== have) { detail.error = format(this.t.countMismatch, q[typeId] ? q[typeId].name : typeId, have, want); return; }
		}
		if (Object.keys(seats).length) detail.payload.seats = seats;
	};

	function init() { document.querySelectorAll('.wpcap-seating[data-url]').forEach(function (box) { if (!box.dataset.ready) { box.dataset.ready = '1'; new Picker(box); } }); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
