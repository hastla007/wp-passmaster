/* Pass Captain – accessible seat picker for the checkout form (SVG + keyboard + list fallback). */
(function () {
	'use strict';
	const SVGNS = 'http://www.w3.org/2000/svg';
	const R = 10;
	// WCAG 2.5.8: seats (targets) at least 24 CSS px, and at least 24 px apart (centre to centre).
	const TARGET = 24;
	// A090: large seats (WCAG 2.5.5, 44 px) – default on touch screens, switchable.
	const TARGET_LARGE = 44;
	const ZOOMS = [1, 1.5, 2, 3];
	// A090: seat shape per category (cycled), so categories differ by shape and not only by colour.
	const SHAPES = ['circle', 'square', 'diamond', 'triangle'];

	/** Smallest distance between two seat centres (SVG units); Infinity with fewer than two seats. */
	function minPitch(seats) {
		const sorted = seats.slice().sort(function (a, b) { return a.x - b.x || a.y - b.y; });
		let best = Infinity;
		for (let i = 0; i < sorted.length; i++) {
			for (let j = i + 1; j < sorted.length && sorted[j].x - sorted[i].x < best; j++) {
				const d = Math.hypot(sorted[j].x - sorted[i].x, sorted[j].y - sorted[i].y);
				if (d > 0 && d < best) best = d;
			}
		}
		return best;
	}
	/** Minimum rendered width (px) of the map so every seat is >= $target px (24) and as far apart, at zoom 1. */
	function minMapWidth(width, seats, target) {
		const size = target || TARGET;
		const pitch = minPitch(seats);
		const scale = Math.max(size / (2 * R), isFinite(pitch) ? size / pitch : 0);
		return Math.min(6000, Math.max(320, Math.ceil(width * scale)));
	}
	/**
	 * Best free seats for `count` tickets: side by side in one row if possible, rows nearer the stage
	 * (top) and nearer the centre first; otherwise the best single seats.
	 */
	function bestSeats(seats, count, width, eligible) {
		const free = seats.filter(function (seat) { return seat.kind === 'seat' && seat.status === 'free' && eligible(seat); });
		if (count < 1 || free.length < count) return [];
		const score = function (seat) { return seat.y + Math.abs(seat.x - width / 2) * 0.5; };
		const rows = {};
		free.forEach(function (seat) { const k = (seat.zone || '') + '|' + (seat.row || seat.y); (rows[k] = rows[k] || []).push(seat); });
		let best = null;
		Object.keys(rows).forEach(function (k) {
			const row = rows[k].sort(function (a, b) { return a.x - b.x; });
			const all = seats.filter(function (seat) { return seat.kind === 'seat' && (seat.zone || '') + '|' + (seat.row || seat.y) === k; }).sort(function (a, b) { return a.x - b.x; });
			for (let i = 0; i + count <= row.length; i++) {
				const block = row.slice(i, i + count);
				// Side by side: no other (taken) seat of the row between the first and the last one.
				const between = all.filter(function (seat) { return seat.x > block[0].x && seat.x < block[count - 1].x; }).length;
				if (between !== count - 2 && count > 1) continue;
				const value = block.reduce(function (sum, seat) { return sum + score(seat); }, 0) / count;
				if (!best || value < best.value) best = { value: value, seats: block };
			}
		});
		if (best) return best.seats;
		return free.sort(function (a, b) { return score(a) - score(b); }).slice(0, count);
	}

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
	/** Whether a media query matches (false without matchMedia). */
	function media(query) { return !!(window.matchMedia && window.matchMedia(query).matches); }
	/** A090: draws a seat shape (circle, square, diamond, triangle) of radius $r around ($x, $y). */
	function shape(parent, kind, x, y, r, fill) {
		let node;
		if (kind === 'square') node = s('rect', { x: x - r * 0.9, y: y - r * 0.9, width: r * 1.8, height: r * 1.8, rx: 2 }, parent);
		else if (kind === 'diamond') node = s('polygon', { points: [x, y - r, x + r, y, x, y + r, x - r, y].join(' ') }, parent);
		else if (kind === 'triangle') node = s('polygon', { points: [x, y - r, x + r, y + r * 0.8, x - r, y + r * 0.8].join(' ') }, parent);
		else node = s('circle', { cx: x, cy: y, r: r }, parent);
		node.setAttribute('fill', fill);
		node.setAttribute('class', 'wpcap-seat-shape');
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

	// A392: until the map was built once, a failed load is retried (after 5, 10, 20, 30 s, and with
	// the 30 s refresh); the first answer that arrives builds the map, without reloading the page.
	Picker.prototype.load = function (first) {
		const self = this;
		if (first) this.say(this.t.loading || '…');
		window.clearTimeout(this.retryTimer);
		fetch(this.url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
			if (!r.ok) throw new Error('http');
			return r.json();
		}).then(function (data) {
			if (data && data.sales_open === false) { self.close(data); return; }
			if (!data || !Array.isArray(data.seats)) throw new Error('data');
			const built = !!self.nodes;
			self.data = data;
			self.byId = new Map(data.seats.map(function (seat) { return [seat.id, seat]; }));
			let lost = false;
			self.selected.forEach(function (type, id) { const seat = self.byId.get(id); if (!seat || seat.status !== 'free') { self.selected.delete(id); lost = true; } });
			if (built) self.update(); else self.build();
			self.say(lost ? self.t.seatTaken : (built ? self.status.textContent : self.t.help || ''));
		}).catch(function () {
			if (self.nodes) return; // Map shown: keep it, the next refresh updates it.
			self.say(self.t.loadFailed || 'Error');
			self.retries = (self.retries || 0) + 1;
			self.retryTimer = window.setTimeout(function () { self.load(false); }, Math.min(30000, 5000 * Math.pow(2, self.retries - 1)));
		});
	};

	// A610: the date was cancelled or its online sale is over. The map goes, the reason is shown
	// and the order form is locked (the payload hook refuses too, the server checks anyway).
	Picker.prototype.close = function (data) {
		this.closed = String(data.message || this.t.closed || '');
		window.clearInterval(this.timer);
		window.clearTimeout(this.retryTimer);
		const self = this;
		Array.prototype.slice.call(this.box.children).forEach(function (child) {
			if (child !== self.status && child.tagName !== 'LEGEND') child.remove();
		});
		this.nodes = null;
		this.selectionList = null;
		this.selected.clear();
		this.box.dataset.closed = String(data.occurrence_status || 'closed');
		this.form.dataset.salesClosed = this.box.dataset.closed;
		this.form.querySelectorAll('.wpcap-qty, button[type="submit"]').forEach(function (node) { node.disabled = true; });
		this.say(this.closed);
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
		return [seat.label, zone, cat, seat.accessible ? t.accessible : '', seat.companion ? t.companion : '', state].filter(Boolean).join(', ');
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
		const shapes = {};
		d.categories.forEach(function (c, i) { colors[c.key] = c.color; shapes[c.key] = SHAPES[i % SHAPES.length]; });
		this.shapes = shapes;
		// A090: the legend shows the shape of each category (colour is never the only difference) and the
		// marks of the seat states (free, selected ✓, taken ×, wheelchair ♿, companion +).
		const legend = h('ul', { className: 'wpcap-seating-legend', 'aria-label': this.t.legend || '' }, this.box);
		d.categories.forEach(function (c) {
			const li = h('li', { 'data-shape': shapes[c.key] }, legend);
			// Same shape as the seats (CSS shape by data-shape), the colour of the category.
			h('span', { className: 'wpcap-seating-swatch', 'data-shape': shapes[c.key], style: 'background:' + c.color, 'aria-hidden': 'true' }, li);
			li.appendChild(document.createTextNode(c.name));
		});
		[['✓', this.t.selected], ['×', this.t.taken], ['♿', this.t.accessible], ['+', this.t.companionShort]].forEach(function (entry) {
			if (!entry[1]) return;
			const li = h('li', { className: 'wpcap-seating-legend-mark' }, legend);
			h('span', { className: 'wpcap-seating-mark', 'aria-hidden': 'true' }, li, entry[0]);
			li.appendChild(document.createTextNode(' ' + entry[1]));
		});

		const tools = h('div', { className: 'wpcap-seating-tools' }, this.box);
		// A090: map or list – the list is a full alternative (same seats, real checkboxes), not a hidden extra.
		const views = h('div', { className: 'wpcap-seating-views', role: 'group', 'aria-label': this.t.view || '' }, tools);
		this.viewButtons = {
			map: h('button', { type: 'button', className: 'wpcap-seating-view', 'data-view': 'map', 'aria-pressed': 'true' }, views, this.t.viewMap || 'Map'),
			list: h('button', { type: 'button', className: 'wpcap-seating-view', 'data-view': 'list', 'aria-pressed': 'false' }, views, this.t.viewList || 'List'),
		};
		this.viewButtons.map.addEventListener('click', function () { self.setView('map', true); });
		this.viewButtons.list.addEventListener('click', function () { self.setView('list', true); });
		const zoomOut = h('button', { type: 'button', className: 'wpcap-seating-zoom', 'data-zoom': '-1', 'aria-label': this.t.zoomOut || '−' }, tools, '−');
		const zoomIn = h('button', { type: 'button', className: 'wpcap-seating-zoom', 'data-zoom': '1', 'aria-label': this.t.zoomIn || '+' }, tools, '+');
		const fit = h('button', { type: 'button', className: 'wpcap-seating-fit' }, tools, this.t.fit || '⤢');
		this.zoomLabel = h('span', { className: 'wpcap-seating-zoom-level', 'aria-live': 'polite' }, tools);
		// A090: seat size – large touch targets (44 px, WCAG 2.5.5) by default on touch screens.
		const large = h('button', { type: 'button', className: 'wpcap-seating-size', 'aria-pressed': 'false' }, tools, this.t.seatLarge || 'Large seats');
		const best = h('button', { type: 'button', className: 'wpcap-seating-best' }, tools, this.t.best || 'Best seats');
		zoomOut.addEventListener('click', function () { self.zoom(-1); });
		zoomIn.addEventListener('click', function () { self.zoom(1); });
		fit.addEventListener('click', function () { self.setZoom(1); if (self.wrap) { self.wrap.scrollLeft = 0; self.wrap.scrollTop = 0; } });
		large.addEventListener('click', function () { self.setSeatSize(self.target === TARGET_LARGE ? TARGET : TARGET_LARGE); });
		best.addEventListener('click', function () { self.best(); });
		this.zoomButtons = [zoomOut, zoomIn];
		this.sizeButton = large;

		const wrap = h('div', { className: 'wpcap-seating-map' }, this.box);
		this.wrap = wrap;
		const svg = s('svg', { viewBox: '0 0 ' + d.width + ' ' + d.height, role: 'group', 'aria-label': this.t.title || '' }, wrap);
		this.svg = svg;
		// Seat density decides the minimum width; the map container scrolls (pan) when it is wider than the screen.
		this.seatedPoints = d.seats.filter(function (seat) { return seat.kind === 'seat'; });
		this.target = media('(pointer: coarse)') ? TARGET_LARGE : TARGET;
		this.baseWidth = minMapWidth(d.width, this.seatedPoints, this.target);
		this.zoomValue = 1;
		this.applyZoom();
		s('text', { x: d.width / 2, y: 16, 'text-anchor': 'middle', class: 'wpcap-seating-stage', 'aria-hidden': 'true' }, svg).textContent = '▭';
		d.areas.forEach(function (area) {
			const rect = s('rect', { x: area.x, y: area.y, width: area.w, height: area.h, rx: 6, fill: colors[area.category] || '#8c8f94', class: 'wpcap-seating-area', 'aria-hidden': 'true' }, svg);
			rect.style.fillOpacity = 0.25;
			s('text', { x: area.x + area.w / 2, y: area.y + area.h / 2, 'text-anchor': 'middle', 'aria-hidden': 'true' }, svg).textContent = area.label;
		});
		this.nodes = new Map();
		this.marks = new Map();
		const seated = d.seats.filter(function (seat) { return seat.kind === 'seat'; });
		seated.forEach(function (seat) {
			const g = s('g', { class: 'wpcap-seat', role: 'checkbox', tabindex: '-1', 'data-id': seat.id, 'data-shape': shapes[seat.category] || 'circle' }, svg);
			shape(g, shapes[seat.category] || 'circle', seat.x, seat.y, R, colors[seat.category] || '#8c8f94');
			self.marks.set(seat.id, s('text', { x: seat.x, y: seat.y + 4, 'text-anchor': 'middle', 'aria-hidden': 'true', class: 'wpcap-seat-icon' }, g));
			g.addEventListener('click', function () {
				if (self.dragged) return; // End of a pan or pinch, not a choice.
				self.focusId = seat.id;
				self.toggle(seat.id);
			});
			self.nodes.set(seat.id, g);
		});
		if (seated.length) this.focusId = (seated.find(function (seat) { return seat.status === 'free'; }) || seated[0]).id;
		svg.addEventListener('keydown', function (evt) { self.key(evt, seated); });
		this.gestures(wrap);

		// List alternative: real checkboxes, grouped by zone and row. Default view on narrow screens with a large map.
		const list = h('div', { className: 'wpcap-seating-list', role: 'group', 'aria-label': this.t.listToggle || '' }, this.box);
		this.listBox = list;
		const groups = {};
		seated.forEach(function (seat) {
			const zone = (d.zones.find(function (z) { return z.key === seat.zone; }) || { name: '' }).name;
			const key = zone + '|' + seat.row;
			(groups[key] = groups[key] || { title: [zone, seat.row].filter(Boolean).join(' – '), seats: [] }).seats.push(seat);
		});
		this.checks = new Map();
		Object.keys(groups).forEach(function (key) {
			const fs = h('fieldset', {}, list);
			h('legend', {}, fs, groups[key].title);
			groups[key].seats.forEach(function (seat) {
				const label = h('label', {}, fs);
				const box = h('input', { type: 'checkbox', value: String(seat.id) }, label);
				const cat = (d.categories.find(function (c) { return c.key === seat.category; }) || {}).name;
				label.appendChild(document.createTextNode(' ' + seat.seat + (cat && d.categories.length > 1 ? ' (' + cat + ')' : '') + (seat.accessible ? ' ♿' : '') + (seat.companion ? ' +' : '')));
				box.addEventListener('change', function () { self.toggle(seat.id); });
				self.checks.set(seat.id, box);
			});
		});
		const narrow = media('(max-width: 600px)');
		const auto = narrow && seated.length > 40;
		if (auto && this.t.listHint) h('p', { className: 'wpcap-seating-hint' }, this.box, this.t.listHint);
		this.setView(auto || !seated.length ? 'list' : 'map', false);
		this.areaInputs = new Map();
		d.areas.forEach(function (area) {
			const label = h('label', { className: 'wpcap-seating-standing' }, self.box, area.label + ' – ' + (self.t.standing || '') + ' ');
			const input = h('input', { type: 'number', min: '0', value: '0', inputmode: 'numeric' }, label);
			input.addEventListener('change', function () { self.addStanding(area.key, Math.max(0, Number(input.value) || 0)); });
			self.areaInputs.set(area.key, input);
		});

		const chosen = h('div', { className: 'wpcap-seating-selection' }, this.box);
		this.selectionHeading = h('h3', { tabindex: '-1' }, chosen, this.t.yourSeats || '');
		this.selectionList = h('ul', {}, chosen);
		this.update();
	};

	/** A090: shows the map or the list (the other one is hidden); $focus moves focus into the shown view. */
	Picker.prototype.setView = function (view, focus) {
		this.view = view === 'list' ? 'list' : 'map';
		const list = this.view === 'list';
		this.listBox.hidden = !list;
		this.wrap.hidden = list;
		this.viewButtons.map.setAttribute('aria-pressed', list ? 'false' : 'true');
		this.viewButtons.list.setAttribute('aria-pressed', list ? 'true' : 'false');
		this.zoomButtons.concat([this.sizeButton]).forEach(function (button) { button.hidden = list; });
		if (this.zoomLabel) this.zoomLabel.hidden = list;
		this.box.dataset.view = this.view;
		if (!focus) return;
		const target = list ? this.listBox.querySelector('input:not([disabled])') : this.nodes.get(this.focusId);
		if (target && target.focus) target.focus();
	};

	/** A090: minimum seat target (24 or 44 px); the map width follows. */
	Picker.prototype.setSeatSize = function (target) {
		this.target = target;
		this.baseWidth = minMapWidth(this.data.width, this.seatedPoints, target);
		this.applyZoom();
		this.say(format(target === TARGET_LARGE ? (this.t.seatLargeOn || '%d px') : (this.t.seatLargeOff || '%d px'), target));
	};

	Picker.prototype.applyZoom = function () {
		const zoom = this.zoomValue;
		this.svg.style.width = Math.round(100 * zoom) + '%';
		this.svg.style.minWidth = Math.round(this.baseWidth * zoom) + 'px';
		if (this.zoomLabel) this.zoomLabel.textContent = format(this.t.zoomLevel || '%d %', Math.round(zoom * 100));
		if (this.zoomButtons) {
			this.zoomButtons[0].disabled = zoom <= ZOOMS[0] + 0.001;
			this.zoomButtons[1].disabled = zoom >= ZOOMS[ZOOMS.length - 1] - 0.001;
		}
		if (this.sizeButton) this.sizeButton.setAttribute('aria-pressed', this.target === TARGET_LARGE ? 'true' : 'false');
	};

	/**
	 * A090: zoom to $value (clamped to the zoom range) keeping the point ($x, $y) of the visible map area (px
	 * from its top left corner; default the centre) in place, so pinching zooms where the fingers are.
	 */
	Picker.prototype.setZoom = function (value, x, y) {
		const wrap = this.wrap;
		const before = this.zoomValue;
		const zoom = Math.max(ZOOMS[0], Math.min(ZOOMS[ZOOMS.length - 1], value));
		const cx = x === undefined ? (wrap.clientWidth || 0) / 2 : x;
		const cy = y === undefined ? (wrap.clientHeight || 0) / 2 : y;
		const left = (wrap.scrollLeft || 0) + cx;
		const top = (wrap.scrollTop || 0) + cy;
		this.zoomValue = zoom;
		this.applyZoom();
		const ratio = zoom / before;
		wrap.scrollLeft = Math.max(0, left * ratio - cx);
		wrap.scrollTop = Math.max(0, top * ratio - cy);
	};

	/** Zoom buttons: the next step of ZOOMS. */
	Picker.prototype.zoom = function (dir) {
		const zoom = this.zoomValue;
		const next = dir > 0 ? ZOOMS.find(function (z) { return z > zoom + 0.001; }) : ZOOMS.slice().reverse().find(function (z) { return z < zoom - 0.001; });
		this.setZoom(next === undefined ? zoom : next);
	};

	/**
	 * A090: gestures on the map – pinch with two fingers zooms, dragging with the mouse pans (touch pans
	 * natively, CSS touch-action), Ctrl/⌘ + wheel zooms. A gesture that moved more than a few pixels does
	 * not select the seat it ended on.
	 */
	Picker.prototype.gestures = function (wrap) {
		const self = this;
		const pointers = new Map();
		let pinch = null;
		let drag = null;
		const point = function (evt) {
			const box = wrap.getBoundingClientRect ? wrap.getBoundingClientRect() : { left: 0, top: 0 };
			return { x: evt.clientX - box.left, y: evt.clientY - box.top };
		};
		wrap.addEventListener('pointerdown', function (evt) {
			self.dragged = false;
			pointers.set(evt.pointerId, { x: evt.clientX, y: evt.clientY });
			if (pointers.size === 2) {
				const p = Array.from(pointers.values());
				pinch = { dist: Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y) || 1, zoom: self.zoomValue };
				drag = null;
			} else if (evt.pointerType === 'mouse' && evt.button === 0) {
				drag = { x: evt.clientX, y: evt.clientY, left: wrap.scrollLeft || 0, top: wrap.scrollTop || 0 };
			}
		});
		wrap.addEventListener('pointermove', function (evt) {
			if (!pointers.has(evt.pointerId)) return;
			pointers.set(evt.pointerId, { x: evt.clientX, y: evt.clientY });
			if (pinch && pointers.size === 2) {
				const p = Array.from(pointers.values());
				const dist = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
				const mid = point({ clientX: (p[0].x + p[1].x) / 2, clientY: (p[0].y + p[1].y) / 2 });
				self.setZoom(pinch.zoom * dist / pinch.dist, mid.x, mid.y);
				self.dragged = true;
				if (evt.preventDefault) evt.preventDefault();
			} else if (drag) {
				const dx = evt.clientX - drag.x;
				const dy = evt.clientY - drag.y;
				if (Math.abs(dx) + Math.abs(dy) > 6) self.dragged = true;
				if (self.dragged) { wrap.scrollLeft = drag.left - dx; wrap.scrollTop = drag.top - dy; }
			}
		});
		const end = function (evt) {
			pointers.delete(evt.pointerId);
			if (pointers.size < 2) pinch = null;
			if (!pointers.size) drag = null;
		};
		wrap.addEventListener('pointerup', end);
		wrap.addEventListener('pointercancel', end);
		wrap.addEventListener('wheel', function (evt) {
			if (!evt.ctrlKey && !evt.metaKey) return;
			if (evt.preventDefault) evt.preventDefault();
			const at = point(evt);
			self.setZoom(self.zoomValue * (evt.deltaY < 0 ? 1.25 : 0.8), at.x, at.y);
		}, { passive: false });
	};

	/** "Beste verfügbare Plätze": proposes seats for the ordered quantities that still need one. */
	Picker.prototype.best = function () {
		const self = this;
		const q = this.quantities();
		const used = {};
		this.selected.forEach(function (type) { used[type] = (used[type] || 0) + 1; });
		let need = 0;
		const open = Object.keys(this.data.types || {}).filter(function (id) {
			const missing = (q[id] ? q[id].qty : 0) - (used[id] || 0);
			if (missing > 0) need += missing;
			return missing > 0;
		});
		const chosen = need ? bestSeats(this.data.seats.filter(function (seat) { return !self.selected.has(seat.id); }), need, this.data.width, function (seat) { return self.eligibleTypes(seat).some(function (id) { return open.indexOf(id) !== -1; }); }) : [];
		if (!chosen.length) { this.say(this.t.bestNone || ''); return; }
		const labels = [];
		chosen.forEach(function (seat) {
			const type = self.pickType(seat);
			if (type) { self.selected.set(seat.id, type); labels.push(seat.label); }
		});
		this.focusId = chosen[0].id;
		this.update();
		this.say(format(this.t.bestDone || '%s', labels.join(', ')));
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
			// A090: state as a mark in the seat (not only colour): ✓ selected, × taken, else ♿ / + for special seats.
			const mark = self.marks.get(id);
			if (mark) mark.textContent = selected ? '✓' : (seat.status !== 'free' ? '×' : (seat.accessible ? '♿' : (seat.companion ? '+' : '')));
			const box = self.checks.get(id);
			if (box) { box.checked = selected; box.disabled = seat.status !== 'free' && !selected; }
		});
		this.areaInputs.forEach(function (input, key) {
			const spots = self.data.seats.filter(function (seat) { return seat.kind === 'standing' && seat.group === key; });
			input.max = String(spots.filter(function (seat) { return seat.status === 'free' || self.selected.has(seat.id); }).length);
			// Not while the buyer is typing a number into it (the 30 s refresh would overwrite it).
			if (document.activeElement !== input) input.value = String(spots.filter(function (seat) { return self.selected.has(seat.id); }).length);
		});
		this.renderSelection();
	};

	// The list of chosen seats is rebuilt only when its content changes (not on every 30 s refresh),
	// and keyboard focus inside it survives a rebuild: same control of the same seat, or after
	// "Entfernen" the next (else previous) seat's button, else the heading (A312).
	Picker.prototype.renderSelection = function () {
		const self = this;
		if (!this.selectionList) return;
		const q = this.quantities();
		const entries = [];
		this.selected.forEach(function (type, id) { entries.push([id, String(type)]); });
		const signature = JSON.stringify([entries, Object.keys(q).map(function (k) { return [k, q[k].name]; })]);
		if (signature === this.selectionSignature) return;
		this.selectionSignature = signature;
		const items = Array.prototype.slice.call(this.selectionList.querySelectorAll('li[data-seat]'));
		const active = document.activeElement;
		let focus = null;
		if (active && active !== this.selectionList && this.selectionList.contains(active)) {
			const li = active.closest('li');
			focus = { seat: li ? li.getAttribute('data-seat') : null, select: active.tagName === 'SELECT', index: items.indexOf(li) };
		}
		this.selectionList.textContent = '';
		if (!this.selected.size) {
			h('li', {}, this.selectionList, this.t.none || '');
		} else {
			this.selected.forEach(function (type, id) {
				const seat = self.byId.get(id);
				const li = h('li', { 'data-seat': String(id) }, self.selectionList, seat.label + ' ');
				const select = h('select', { 'aria-label': (self.t.ticketType || '') + ': ' + seat.label }, li);
				self.eligibleTypes(seat).forEach(function (typeId) {
					const option = h('option', { value: typeId }, select, q[typeId] ? q[typeId].name : '#' + typeId);
					if (String(typeId) === String(type)) option.selected = true;
				});
				select.addEventListener('change', function () { self.selected.set(id, select.value); self.renderSelection(); });
				const remove = h('button', { type: 'button', className: 'wpcap-seating-remove' }, li, self.t.remove || 'x');
				remove.addEventListener('click', function () { self.toggle(id); });
			});
		}
		if (!focus) return;
		const now = Array.prototype.slice.call(this.selectionList.querySelectorAll('li[data-seat]'));
		let li = now.filter(function (item) { return item.getAttribute('data-seat') === focus.seat; })[0] || null;
		let select = focus.select;
		if (!li && now.length) { li = now[Math.min(Math.max(focus.index, 0), now.length - 1)]; select = false; }
		const target = li ? li.querySelector(select ? 'select' : '.wpcap-seating-remove') : this.selectionHeading;
		if (target) target.focus();
	};

	Picker.prototype.payload = function (detail) {
		if (this.closed) { detail.error = this.closed; return; }
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
	// Test hook for tests/js (node:test); `module` does not exist in the browser, so this is a no-op there.
	if (typeof module === 'object' && module && module.exports) { module.exports = { Picker: Picker, format: format, init: init, minMapWidth: minMapWidth, minPitch: minPitch, bestSeats: bestSeats, SHAPES: SHAPES }; }
})();
