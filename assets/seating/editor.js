/* WP Pass Captain – graphical seat-map editor (no build step, no dependencies). */
(function () {
	'use strict';
	const root = document.getElementById('wpcap-seat-editor');
	const form = document.getElementById('wpcap-seat-editor-form');
	const field = document.getElementById('wpcap-seat-editor-draft');
	if (!root || !form || !field) return;

	const SVGNS = 'http://www.w3.org/2000/svg';
	const GRID = 5;
	const R = 10;
	const t = parse(root.dataset.i18n, {});
	const locked = parse(root.dataset.locked, {});
	const draft = Object.assign({ width: 1000, height: 700, zones: [], categories: [], groups: [], seats: [] }, parse(root.dataset.draft, {}));
	let selection = new Set();
	let selectedArea = null;
	let dirty = false;
	let counter = 0;

	function parse(text, fallback) { try { return JSON.parse(text || '') || fallback; } catch (e) { return fallback; } }
	function uid(prefix) { counter += 1; return prefix + Date.now().toString(36) + counter.toString(36); }
	function snap(v) { return Math.max(0, Math.round(v / GRID) * GRID); }
	function el(tag, attrs, parent, ns) {
		const node = ns === false ? document.createElement(tag) : document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
		if (parent) parent.appendChild(node);
		return node;
	}
	function h(tag, attrs, parent, text) {
		const node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { if (k === 'className') node.className = attrs[k]; else node.setAttribute(k, attrs[k]); });
		if (text !== undefined) node.textContent = text;
		if (parent) parent.appendChild(node);
		return node;
	}
	function seatByKey(key) { return draft.seats.find(function (s) { return s.key === key; }); }
	function groupByKey(key) { return draft.groups.find(function (g) { return g.key === key; }); }
	function colorOf(list, key) { const item = draft[list].find(function (i) { return i.key === key; }); return item ? item.color : '#8c8f94'; }
	function isLocked(seat) { return Object.prototype.hasOwnProperty.call(locked, seat.key); }
	function standingLockedMax(group) {
		let max = 0;
		Object.keys(locked).forEach(function (k) {
			if (locked[k].kind === 'standing' && k.indexOf(group.key.slice(0, 54) + '-') === 0) { max = Math.max(max, parseInt(k.slice(group.key.slice(0, 54).length + 1), 10) || 0); }
		});
		return max;
	}
	function changed() { dirty = true; status.textContent = t.unsaved || '*'; render(); }

	// Sold/held seats always keep their snapshot (restored if missing or changed in the draft).
	Object.keys(locked).forEach(function (key) {
		const snapshot = locked[key];
		if (snapshot.kind !== 'seat') return;
		let seat = seatByKey(key);
		if (!seat) { seat = { key: key, group: '' }; draft.seats.push(seat); }
		Object.assign(seat, { row: snapshot.row, label: snapshot.label, zone: snapshot.zone, category: snapshot.category, x: snapshot.x, y: snapshot.y });
	});

	/* ---- Layout ---- */
	root.textContent = '';
	const toolbar = h('div', { className: 'wpcap-se-toolbar', role: 'toolbar', 'aria-label': 'Werkzeuge' }, root);
	const main = h('div', { className: 'wpcap-se-main' }, root);
	const canvasWrap = h('div', { className: 'wpcap-se-canvas' }, main);
	const svg = el('svg', { viewBox: '0 0 ' + draft.width + ' ' + draft.height, tabindex: '0', role: 'application', 'aria-label': 'Saalplan', 'aria-describedby': 'wpcap-se-help' }, canvasWrap);
	const panel = h('div', { className: 'wpcap-se-panel' }, main);
	const status = h('p', { className: 'wpcap-se-status', role: 'status', 'aria-live': 'polite' }, root);
	h('p', { id: 'wpcap-se-help', className: 'description' }, root, 'Klicken wählt einen Platz, Umschalt+Klick erweitert die Auswahl, Doppelklick wählt die ganze Gruppe. Ziehen oder Pfeiltasten verschieben (Umschalt: 1 px), Entf löscht, Esc hebt die Auswahl auf.');

	function numberInput(parent, label, value, min, max) {
		const wrap = h('label', {}, parent, label + ' ');
		const input = h('input', { type: 'number', min: String(min), max: String(max), value: String(value) }, wrap);
		return input;
	}
	function textInput(parent, label, value) {
		const wrap = h('label', {}, parent, label + ' ');
		return h('input', { type: 'text', value: value || '', maxlength: '60' }, wrap);
	}
	function listSelect(parent, label, list, value) {
		const wrap = h('label', {}, parent, label + ' ');
		const select = h('select', {}, wrap);
		h('option', { value: '' }, select, t.none || '—');
		draft[list].forEach(function (item) { const o = h('option', { value: item.key }, select, item.name); if (item.key === value) o.selected = true; });
		return select;
	}
	function nextRowLetter() {
		const used = draft.seats.map(function (s) { return s.row; });
		for (let i = 0; i < 26 * 27; i++) {
			const name = (t.row || 'Reihe') + ' ' + (i < 26 ? String.fromCharCode(65 + i) : String.fromCharCode(64 + Math.floor(i / 26)) + String.fromCharCode(65 + i % 26));
			if (used.indexOf(name) === -1) return name;
		}
		return t.row || 'Reihe';
	}
	function freeSpot(height) {
		let maxY = 20;
		draft.seats.forEach(function (s) { maxY = Math.max(maxY, s.y + 2 * R); });
		draft.groups.forEach(function (g) { if (g.type === 'standing') maxY = Math.max(maxY, g.y + g.h); });
		return Math.min(draft.height - height - 10, maxY + 20);
	}

	/* ---- Toolbar: generators ---- */
	const tools = h('details', { className: 'wpcap-se-tools', open: '' }, toolbar);
	h('summary', {}, tools, 'Hinzufügen');
	const toolGrid = h('div', { className: 'wpcap-se-toolgrid' }, tools);
	const defaults = h('fieldset', {}, toolGrid); h('legend', {}, defaults, 'Standard für neue Plätze');
	const defZone = listSelect(defaults, t.zone || 'Bereich', 'zones', draft.zones[0] ? draft.zones[0].key : '');
	const defCat = listSelect(defaults, t.category || 'Kategorie', 'categories', draft.categories[0] ? draft.categories[0].key : '');
	const spacing = numberInput(defaults, 'Abstand', 30, 22, 100);

	const rowBox = h('fieldset', {}, toolGrid); h('legend', {}, rowBox, t.row || 'Reihe');
	const rowSeats = numberInput(rowBox, t.seats || 'Plätze', 10, 1, 200);
	const rowStart = numberInput(rowBox, 'ab Nr.', 1, 0, 9999);
	h('button', { type: 'button', className: 'button' }, rowBox, '+ ' + (t.row || 'Reihe')).addEventListener('click', function () {
		addRows(1, Number(rowSeats.value) || 1, Number(rowStart.value) || 1, 'row');
	});
	const blockBox = h('fieldset', {}, toolGrid); h('legend', {}, blockBox, t.block || 'Block');
	const blockRows = numberInput(blockBox, 'Reihen', 5, 1, 60);
	const blockCols = numberInput(blockBox, t.seats || 'Plätze', 10, 1, 100);
	h('button', { type: 'button', className: 'button' }, blockBox, '+ ' + (t.block || 'Block')).addEventListener('click', function () {
		addRows(Number(blockRows.value) || 1, Number(blockCols.value) || 1, 1, 'block');
	});
	const tableBox = h('fieldset', {}, toolGrid); h('legend', {}, tableBox, t.table || 'Tisch');
	const tableSeats = numberInput(tableBox, t.seats || 'Plätze', 8, 2, 24);
	h('button', { type: 'button', className: 'button' }, tableBox, '+ ' + (t.table || 'Tisch')).addEventListener('click', function () { addTable(Number(tableSeats.value) || 8); });
	const standBox = h('fieldset', {}, toolGrid); h('legend', {}, standBox, t.standing || 'Stehbereich');
	const standCap = numberInput(standBox, 'Kapazität', 100, 1, 2000);
	h('button', { type: 'button', className: 'button' }, standBox, '+ ' + (t.standing || 'Stehbereich')).addEventListener('click', function () {
		const g = { key: uid('a'), type: 'standing', label: (t.standing || 'Stehbereich') + ' ' + (draft.groups.filter(function (x) { return x.type === 'standing'; }).length + 1), x: 20, y: snap(freeSpot(120)), w: 240, h: 120, capacity: Math.max(1, Math.min(2000, Number(standCap.value) || 1)), zone: defZone.value, category: defCat.value, accessible: false };
		draft.groups.push(g);
		selection.clear(); selectedArea = g.key;
		changed();
	});

	function addRows(rows, cols, start, type) {
		const sp = Math.max(22, Number(spacing.value) || 30);
		const group = { key: uid('g'), type: type, label: type === 'block' ? (t.block || 'Block') + ' ' + (draft.groups.filter(function (g) { return g.type === 'block'; }).length + 1) : '', x: 20, y: 0, zone: defZone.value, category: defCat.value };
		const y0 = snap(freeSpot(rows * sp));
		group.y = y0;
		draft.groups.push(group);
		selection = new Set(); selectedArea = null;
		for (let r = 0; r < rows; r++) {
			const row = nextRowLetter();
			for (let c = 0; c < cols; c++) {
				const seat = { key: uid('s'), group: group.key, row: row, label: String(start + c), x: snap(40 + c * sp), y: snap(y0 + R + r * sp), zone: defZone.value, category: defCat.value, accessible: false };
				draft.seats.push(seat);
				selection.add(seat.key);
			}
		}
		changed();
	}
	function addTable(n) {
		const radius = Math.max(30, n * 7);
		const number = draft.groups.filter(function (g) { return g.type === 'table'; }).length + 1;
		const cx = snap(radius + 2 * R + 20), cy = snap(freeSpot(2 * radius + 2 * R) + radius + R);
		const group = { key: uid('t'), type: 'table', label: (t.table || 'Tisch') + ' ' + number, x: cx, y: cy, zone: defZone.value, category: defCat.value };
		draft.groups.push(group);
		selection = new Set(); selectedArea = null;
		for (let i = 0; i < n; i++) {
			const a = (2 * Math.PI * i) / n - Math.PI / 2;
			const seat = { key: uid('s'), group: group.key, row: group.label, label: String(i + 1), x: Math.round(cx + Math.cos(a) * radius), y: Math.round(cy + Math.sin(a) * radius), zone: defZone.value, category: defCat.value, accessible: false };
			draft.seats.push(seat);
			selection.add(seat.key);
		}
		changed();
	}

	/* ---- Zones and categories ---- */
	const lists = h('details', { className: 'wpcap-se-lists' }, toolbar);
	h('summary', {}, lists, (t.zone || 'Bereiche') + ' / ' + (t.category || 'Kategorien'));
	function renderLists() {
		lists.querySelectorAll('.wpcap-se-list').forEach(function (n) { n.remove(); });
		[['zones', t.zone || 'Bereich'], ['categories', t.category || 'Kategorie']].forEach(function (pair) {
			const list = pair[0];
			const box = h('fieldset', { className: 'wpcap-se-list' }, lists);
			h('legend', {}, box, pair[1]);
			draft[list].forEach(function (item, index) {
				const row = h('div', {}, box);
				const name = h('input', { type: 'text', value: item.name, 'aria-label': pair[1] + ' ' + item.key, maxlength: '60' }, row);
				name.addEventListener('change', function () { item.name = name.value || item.key; dirty = true; refreshSelects(); });
				const color = h('input', { type: 'color', value: item.color, 'aria-label': 'Farbe' }, row);
				color.addEventListener('change', function () { item.color = color.value; changed(); });
				h('code', {}, row, item.key);
				const used = draft.seats.some(function (s) { return s[list === 'zones' ? 'zone' : 'category'] === item.key; }) || draft.groups.some(function (g) { return g[list === 'zones' ? 'zone' : 'category'] === item.key; });
				const del = h('button', { type: 'button', className: 'button-link' }, row, 'Entfernen');
				del.disabled = used;
				del.addEventListener('click', function () { draft[list].splice(index, 1); changed(); renderLists(); refreshSelects(); });
			});
			const add = h('div', {}, box);
			const input = h('input', { type: 'text', placeholder: 'Name', maxlength: '60', 'aria-label': pair[1] + ' hinzufügen' }, add);
			h('button', { type: 'button', className: 'button' }, add, '+').addEventListener('click', function () {
				const name = input.value.trim();
				if (!name) return;
				let key = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || uid('z');
				while (draft[list].some(function (i) { return i.key === key; })) key += '-2';
				draft[list].push({ key: key, name: name, color: '#' + Math.floor(Math.random() * 0xffffff).toString(16).padStart(6, '0') });
				changed(); renderLists(); refreshSelects();
			});
		});
	}
	function refreshSelects() {
		[[defZone, 'zones'], [defCat, 'categories']].forEach(function (pair) {
			const value = pair[0].value;
			pair[0].textContent = '';
			h('option', { value: '' }, pair[0], t.none || '—');
			draft[pair[1]].forEach(function (item) { const o = h('option', { value: item.key }, pair[0], item.name); if (item.key === value) o.selected = true; });
		});
		renderPanel();
	}

	/* ---- Canvas ---- */
	function render() {
		while (svg.firstChild) svg.removeChild(svg.firstChild);
		el('rect', { x: 0, y: 0, width: draft.width, height: draft.height, class: 'wpcap-se-bg' }, svg);
		el('text', { x: draft.width / 2, y: 16, 'text-anchor': 'middle', class: 'wpcap-se-stage' }, svg).textContent = 'Bühne';
		draft.groups.forEach(function (g) {
			if (g.type === 'standing') {
				const rect = el('rect', { x: g.x, y: g.y, width: g.w, height: g.h, rx: 6, fill: colorOf('categories', g.category), stroke: colorOf('zones', g.zone), class: 'wpcap-se-area' + (selectedArea === g.key ? ' is-selected' : ''), 'data-area': g.key }, svg);
				rect.style.fillOpacity = 0.25;
				el('text', { x: g.x + g.w / 2, y: g.y + g.h / 2, 'text-anchor': 'middle', 'pointer-events': 'none' }, svg).textContent = g.label + ' (' + g.capacity + ')';
			} else if (g.type === 'table') {
				el('circle', { cx: g.x, cy: g.y, r: 18, class: 'wpcap-se-table', 'data-group': g.key }, svg);
				el('text', { x: g.x, y: g.y + 4, 'text-anchor': 'middle', 'pointer-events': 'none', class: 'wpcap-se-small' }, svg).textContent = g.label.replace(/\D+/g, '') || 'T';
			}
		});
		const rowsSeen = {};
		draft.seats.forEach(function (s) {
			if (s.row && !rowsSeen[s.row + '|' + s.group]) {
				rowsSeen[s.row + '|' + s.group] = true;
				const g = groupByKey(s.group);
				if (!g || g.type !== 'table') el('text', { x: Math.max(2, s.x - 2 * R - 2), y: s.y + 4, 'text-anchor': 'end', class: 'wpcap-se-small', 'data-group': s.group }, svg).textContent = s.row.replace(/^\D+\s/, '');
			}
			const gEl = el('g', { 'data-seat': s.key, class: 'wpcap-se-seat' + (selection.has(s.key) ? ' is-selected' : '') + (isLocked(s) ? ' is-locked' : '') }, svg);
			el('circle', { cx: s.x, cy: s.y, r: R, fill: colorOf('categories', s.category), stroke: colorOf('zones', s.zone) }, gEl);
			el('text', { x: s.x, y: s.y + 3.5, 'text-anchor': 'middle', class: 'wpcap-se-label' }, gEl).textContent = s.accessible ? '♿' : s.label;
			el('title', {}, gEl).textContent = (s.row ? s.row + ', ' : '') + s.label + (isLocked(s) ? ' – ' + (t.locked || 'locked') : '');
		});
		field.value = JSON.stringify(draft);
		renderPanel();
	}

	/* ---- Selection, dragging, keyboard ---- */
	function point(evt) {
		const pt = svg.createSVGPoint();
		pt.x = evt.clientX; pt.y = evt.clientY;
		return pt.matrixTransform(svg.getScreenCTM().inverse());
	}
	let drag = null;
	svg.addEventListener('pointerdown', function (evt) {
		const seatNode = evt.target.closest('[data-seat]');
		const areaNode = evt.target.closest('[data-area]');
		const groupNode = evt.target.closest('[data-group]');
		svg.focus();
		if (seatNode) {
			const key = seatNode.getAttribute('data-seat');
			selectedArea = null;
			if (evt.shiftKey) { if (selection.has(key)) selection.delete(key); else selection.add(key); }
			else if (!selection.has(key)) selection = new Set([key]);
		} else if (areaNode) {
			selection = new Set(); selectedArea = areaNode.getAttribute('data-area');
		} else if (groupNode) {
			selectGroup(groupNode.getAttribute('data-group'), evt.shiftKey);
		} else {
			selection = new Set(); selectedArea = null; render(); return;
		}
		const p = point(evt);
		drag = { x: p.x, y: p.y, moved: false };
		svg.setPointerCapture(evt.pointerId);
		render();
	});
	svg.addEventListener('dblclick', function (evt) {
		const seatNode = evt.target.closest('[data-seat]');
		if (seatNode) { const s = seatByKey(seatNode.getAttribute('data-seat')); if (s && s.group) selectGroup(s.group, false); render(); }
	});
	svg.addEventListener('pointermove', function (evt) {
		if (!drag) return;
		const p = point(evt);
		const dx = snap(p.x - drag.x + 1000) - 1000, dy = snap(p.y - drag.y + 1000) - 1000;
		if (!dx && !dy) return;
		if (moveSelection(dx, dy)) { drag.x += dx; drag.y += dy; drag.moved = true; render(); }
	});
	function endDrag() { if (drag && drag.moved) changed(); drag = null; }
	svg.addEventListener('pointerup', endDrag);
	svg.addEventListener('pointercancel', endDrag);
	svg.addEventListener('keydown', function (evt) {
		const step = evt.shiftKey ? 1 : GRID;
		const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
		if (moves[evt.key]) { evt.preventDefault(); if (moveSelection(moves[evt.key][0], moves[evt.key][1])) changed(); }
		else if (evt.key === 'Delete' || evt.key === 'Backspace') { evt.preventDefault(); deleteSelection(); }
		else if (evt.key === 'Escape') { selection = new Set(); selectedArea = null; render(); }
		else if (evt.key === 'a' && (evt.ctrlKey || evt.metaKey)) { evt.preventDefault(); selection = new Set(draft.seats.map(function (s) { return s.key; })); render(); }
	});
	function selectGroup(key, add) {
		if (!add) selection = new Set();
		selectedArea = null;
		draft.seats.forEach(function (s) { if (s.group === key) selection.add(s.key); });
	}
	function selectedSeats() { return draft.seats.filter(function (s) { return selection.has(s.key); }); }
	function moveSelection(dx, dy) {
		if (selectedArea) {
			const g = groupByKey(selectedArea);
			g.x = Math.max(0, Math.min(draft.width - g.w, g.x + dx)); g.y = Math.max(0, Math.min(draft.height - g.h, g.y + dy));
			return true;
		}
		const seats = selectedSeats();
		if (!seats.length) return false;
		if (seats.some(isLocked)) { status.textContent = t.lockedDelete || ''; return false; }
		if (seats.some(function (s) { return s.x + dx < R || s.y + dy < R || s.x + dx > draft.width - R || s.y + dy > draft.height - R; })) return false;
		seats.forEach(function (s) { s.x += dx; s.y += dy; });
		const groups = {};
		seats.forEach(function (s) { groups[s.group] = true; });
		Object.keys(groups).forEach(function (key) {
			const g = groupByKey(key);
			if (g && g.type === 'table' && draft.seats.every(function (s) { return s.group !== key || selection.has(s.key); })) { g.x += dx; g.y += dy; }
		});
		return true;
	}
	function deleteSelection() {
		if (selectedArea) {
			const g = groupByKey(selectedArea);
			if (standingLockedMax(g) > 0) { status.textContent = t.lockedDelete || ''; return; }
			if (!window.confirm(t.confirmDelete || 'Delete?')) return;
			draft.groups = draft.groups.filter(function (x) { return x.key !== selectedArea; });
			selectedArea = null; changed(); return;
		}
		const seats = selectedSeats();
		if (!seats.length) return;
		if (seats.some(isLocked)) { status.textContent = t.lockedDelete || ''; return; }
		if (!window.confirm(t.confirmDelete || 'Delete?')) return;
		draft.seats = draft.seats.filter(function (s) { return !selection.has(s.key); });
		draft.groups = draft.groups.filter(function (g) { return g.type === 'standing' || draft.seats.some(function (s) { return s.group === g.key; }); });
		selection = new Set(); changed();
	}

	/* ---- Properties panel ---- */
	function renderPanel() {
		panel.textContent = '';
		if (selectedArea) {
			const g = groupByKey(selectedArea);
			const lockedMax = standingLockedMax(g);
			h('h3', {}, panel, t.standing || 'Stehbereich');
			const label = textInput(panel, 'Name', g.label);
			const cap = numberInput(panel, 'Kapazität', g.capacity, Math.max(1, lockedMax), 2000);
			const w = numberInput(panel, 'Breite', g.w, 20, draft.width);
			const hh = numberInput(panel, 'Höhe', g.h, 20, draft.height);
			const zone = listSelect(panel, t.zone || 'Bereich', 'zones', g.zone);
			const cat = listSelect(panel, t.category || 'Kategorie', 'categories', g.category);
			const accWrap = h('label', {}, panel); const acc = h('input', { type: 'checkbox' }, accWrap); acc.checked = !!g.accessible; accWrap.appendChild(document.createTextNode(' rollstuhlgerecht'));
			if (lockedMax) { zone.disabled = true; cat.disabled = true; label.disabled = true; h('p', { className: 'description' }, panel, t.locked || ''); }
			h('button', { type: 'button', className: 'button' }, panel, 'Übernehmen').addEventListener('click', function () {
				g.label = label.value; g.capacity = Math.max(lockedMax, Math.max(1, Math.min(2000, Number(cap.value) || 1)));
				g.w = Math.max(20, Number(w.value) || 20); g.h = Math.max(20, Number(hh.value) || 20);
				if (!lockedMax) { g.zone = zone.value; g.category = cat.value; }
				g.accessible = acc.checked; changed();
			});
			h('button', { type: 'button', className: 'button-link wpcap-se-delete' }, panel, 'Löschen').addEventListener('click', deleteSelection);
			return;
		}
		const seats = selectedSeats();
		if (!seats.length) {
			h('p', {}, panel, t.nothingSelected || '');
			h('p', {}, panel, draft.seats.length + ' ' + (t.seats || 'Plätze') + ', ' + draft.groups.filter(function (g) { return g.type === 'standing'; }).length + ' ' + (t.standing || 'Stehbereiche'));
			return;
		}
		const anyLocked = seats.some(isLocked);
		h('h3', {}, panel, (t.selected || 'Ausgewählt') + ': ' + seats.length);
		if (anyLocked) h('p', { className: 'description' }, panel, t.locked || '');
		const single = seats.length === 1 ? seats[0] : null;
		const row = textInput(panel, t.row || 'Reihe', single ? single.row : (seats.every(function (s) { return s.row === seats[0].row; }) ? seats[0].row : ''));
		const label = single ? textInput(panel, 'Platz', single.label) : null;
		const zone = listSelect(panel, t.zone || 'Bereich', 'zones', seats.every(function (s) { return s.zone === seats[0].zone; }) ? seats[0].zone : '');
		const cat = listSelect(panel, t.category || 'Kategorie', 'categories', seats.every(function (s) { return s.category === seats[0].category; }) ? seats[0].category : '');
		const accWrap = h('label', {}, panel); const acc = h('input', { type: 'checkbox' }, accWrap); acc.checked = seats.every(function (s) { return s.accessible; }); accWrap.appendChild(document.createTextNode(' rollstuhlgerecht'));
		const renumber = single ? null : numberInput(panel, 'Neu nummerieren ab (leer = nein)', '', 0, 9999);
		if (renumber) renumber.value = '';
		[row, label, zone, cat, renumber].forEach(function (input) { if (input && anyLocked) input.disabled = true; });
		h('button', { type: 'button', className: 'button' }, panel, 'Übernehmen').addEventListener('click', function () {
			seats.forEach(function (s) {
				if (!isLocked(s)) {
					if (row.value.trim() || single) s.row = row.value.trim();
					if (zone.value || single) s.zone = zone.value;
					if (cat.value || single) s.category = cat.value;
				}
				s.accessible = isLocked(s) ? s.accessible : acc.checked;
			});
			if (single && label.value.trim() && !isLocked(single)) single.label = label.value.trim();
			if (renumber && renumber.value !== '' && !anyLocked) {
				let n = Number(renumber.value) || 0;
				seats.slice().sort(function (a, b) { return a.y - b.y || a.x - b.x; }).forEach(function (s) { s.label = String(n++); });
			}
			changed();
		});
		h('button', { type: 'button', className: 'button-link wpcap-se-delete' }, panel, 'Löschen').addEventListener('click', deleteSelection);
	}

	form.addEventListener('submit', function () { field.value = JSON.stringify(draft); dirty = false; });
	window.addEventListener('beforeunload', function (evt) { if (dirty) { evt.preventDefault(); evt.returnValue = ''; } });
	renderLists();
	render();
})();
