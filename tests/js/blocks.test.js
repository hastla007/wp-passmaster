/**
 * Block editor scripts (src/blocks/{events,calendar,service-booking}/editor.js) in Node with a
 * stand-in of the wp.* globals: createElement builds a plain tree that the tests walk.
 * A451: the ServerSideRender preview sits inside wp.components.Disabled.
 * A453: event/date selection, including a saved selection that is no longer listed.
 * A455: editor script ↔ block.json, dynamic save, iframe editor (no document access, only wp.* and
 *       the WPCAP_BLOCK_* inline data, one element with the useBlockProps() props).
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const BLOCKS = path.join(__dirname, '..', '..', 'src', 'blocks');

/** Runs a block's editor.js; returns the registered name/settings and an edit() helper. */
function loadBlock(dir, globals) {
	const registered = [];
	const el = (type, props, ...children) => ({ type, props: props || {}, children: children.flat() });
	const sprintf = (format, ...args) => { let i = 0; return String(format).replace(/%(\d\$)?[sd]/g, (m, pos) => String(args[pos ? parseInt(pos, 10) - 1 : i++])); };
	const wp = {
		blocks: { registerBlockType: (name, settings) => { registered.push({ name, settings }); return settings; } },
		element: { createElement: el, Fragment: 'Fragment' },
		serverSideRender: 'ServerSideRender',
		blockEditor: { useBlockProps: (extra) => Object.assign({ className: 'block-editor-block' }, extra || {}), InspectorControls: 'InspectorControls' },
		components: { Disabled: 'Disabled', PanelBody: 'PanelBody', SelectControl: 'SelectControl', TextControl: 'TextControl', Placeholder: 'Placeholder' },
		i18n: { __: (s) => s, sprintf },
	};
	if (globals && globals.noDisabled) { delete wp.components.Disabled; globals = Object.assign({}, globals); delete globals.noDisabled; }
	const sandbox = Object.assign({ wp }, globals || {});
	sandbox.window = sandbox;
	vm.createContext(sandbox);
	vm.runInContext(fs.readFileSync(path.join(BLOCKS, dir, 'editor.js'), 'utf8'), sandbox);
	assert.strictEqual(registered.length, 1, dir + ' registers exactly one block');
	const block = registered[0];
	return {
		name: block.name,
		settings: block.settings,
		edit(attributes) {
			const changes = [];
			const tree = block.settings.edit({ attributes, setAttributes: (a) => changes.push(a) });
			return { tree, changes };
		},
	};
}

/** All nodes of a tree with their ancestor chain. */
function nodes(tree, ancestors, out) {
	out = out || [];
	ancestors = ancestors || [];
	if (!tree || typeof tree !== 'object') return out;
	out.push({ node: tree, ancestors });
	(tree.children || []).forEach((child) => nodes(child, ancestors.concat([tree]), out));
	return out;
}
function find(tree, type) { return nodes(tree).filter((n) => n.node.type === type); }

const CASES = [
	{ dir: 'events', name: 'wp-pass-captain/events', attributes: { event: 0, occurrence: 0 } },
	{ dir: 'calendar', name: 'wp-pass-captain/calendar', attributes: { view: 'month', eventId: 0 } },
	{ dir: 'service-booking', name: 'pass-captain/service-booking', attributes: { eventId: 3, resourceId: 0 } },
];

for (const c of CASES) {
	test('A451: ' + c.name + ' renders the server preview inside Disabled', () => {
		const block = loadBlock(c.dir);
		assert.strictEqual(block.name, c.name);
		const { tree } = block.edit(c.attributes);
		const previews = find(tree, 'ServerSideRender');
		assert.strictEqual(previews.length, 1);
		assert.strictEqual(previews[0].node.props.block, c.name);
		assert.ok(previews[0].ancestors.some((a) => a.type === 'Disabled'), 'ServerSideRender has a Disabled ancestor');
		// The inspector controls stay usable: not inside Disabled.
		find(tree, 'InspectorControls').forEach((n) => assert.ok(!n.ancestors.some((a) => a.type === 'Disabled')));
	});
}

test('A451: without wp.components.Disabled (very old editor) the preview still renders', () => {
	const block = loadBlock('calendar', { noDisabled: true });
	const { tree } = block.edit({ view: 'list', eventId: 0 });
	assert.strictEqual(find(tree, 'ServerSideRender').length, 1);
	assert.strictEqual(find(tree, 'Disabled').length, 0);
});

/* ------------------------------------------------------------------ A453 */

const CHOICES = { events: [
	{ id: 5, label: 'Sommerfest (01.07.2027 19:00, #5)', dates: 2, more: false, occurrences: [{ id: 51, label: '01.07.2027 19:00' }, { id: 52, label: '02.07.2027 19:00' }] },
	{ id: 6, label: 'Kurs (03.07.2027 10:00, #6)', dates: 250, more: true, occurrences: [{ id: 61, label: '03.07.2027 10:00' }] },
] };
/** Copy into this realm (values built inside the vm context fail deepStrictEqual on prototypes). */
const plain = (v) => JSON.parse(JSON.stringify(v));
function selects(tree) {
	const out = {};
	find(tree, 'SelectControl').forEach((n) => { out[n.node.props.label] = n.node.props; });
	return out;
}

test('A453: listed event and date are selected as such', () => {
	const block = loadBlock('events', { WPCAP_BLOCK_EVENTS: CHOICES });
	const s = selects(block.edit({ event: 5, occurrence: 52 }).tree);
	assert.strictEqual(s.Event.value, '5');
	assert.deepStrictEqual(plain(s.Event.options.map((o) => o.value)), ['0', '5', '6']);
	assert.strictEqual(s.Termin.value, '52');
	assert.deepStrictEqual(plain(s.Termin.options.map((o) => o.value)), ['0', '51', '52']);
	assert.strictEqual(s.Termin.help, undefined);
});

test('A453: a saved event that is no longer listed is not shown as "Alle Events"', () => {
	const block = loadBlock('events', { WPCAP_BLOCK_EVENTS: CHOICES });
	const s = selects(block.edit({ event: 9, occurrence: 0 }).tree);
	assert.strictEqual(s.Event.value, '9');
	const selected = s.Event.options.filter((o) => o.value === s.Event.value);
	assert.deepStrictEqual(plain(selected.map((o) => o.label)), ['Event #9 (nicht mehr verfügbar)']);
	assert.ok(s.Termin, 'date select stays available for the saved event');
});

test('A453: a saved date that is no longer listed keeps its own option', () => {
	const block = loadBlock('events', { WPCAP_BLOCK_EVENTS: CHOICES });
	const s = selects(block.edit({ event: 5, occurrence: 59 }).tree);
	assert.deepStrictEqual(plain(s.Termin.options.filter((o) => o.value === '59').map((o) => o.label)), ['Termin #59 (nicht mehr verfügbar)']);
	// Event with a cut date list: the date may just be later than the listed ones.
	const t = selects(block.edit({ event: 6, occurrence: 99 }).tree);
	assert.deepStrictEqual(plain(t.Termin.options.filter((o) => o.value === '99').map((o) => o.label)), ['Termin #99']);
	assert.strictEqual(t.Termin.help, 'Angezeigt werden die nächsten 1 von 250 Terminen.');
});

test('A453: choosing another event resets the date', () => {
	const block = loadBlock('events', { WPCAP_BLOCK_EVENTS: CHOICES });
	const { tree, changes } = block.edit({ event: 5, occurrence: 51 });
	selects(tree).Event.onChange('6');
	assert.deepStrictEqual(plain(changes), [{ event: 6, occurrence: 0 }]);
});

/* ------------------------------------------------------------------ A455 */

for (const c of CASES) {
	const dir = path.join(BLOCKS, c.dir);
	const json = JSON.parse(fs.readFileSync(path.join(dir, 'block.json'), 'utf8'));
	const source = fs.readFileSync(path.join(dir, 'editor.js'), 'utf8');

	test('A455: ' + c.name + ' editor script matches block.json', () => {
		const block = loadBlock(c.dir);
		assert.strictEqual(block.name, json.name);
		assert.strictEqual(json.apiVersion, 3);
		const used = [...new Set([...source.matchAll(/attrs\.([A-Za-z]+)/g)].map((m) => m[1]))];
		assert.ok(used.length > 0);
		used.forEach((name) => assert.ok(Object.prototype.hasOwnProperty.call(json.attributes, name), name + ' is declared in block.json'));
		assert.strictEqual(block.settings.save(), null, 'dynamic block: save() returns null');
	});

	test('A455: ' + c.name + ' works in the iframed editor', () => {
		// The script runs in the editor window while the canvas is an iframe: no direct DOM access,
		// only wp.* packages and the inline data handed over by PHP.
		assert.ok(!/\bdocument\b/.test(source), 'no document access');
		const globals = [...source.matchAll(/window\.([A-Za-z_]+)/g)].map((m) => m[1]);
		globals.forEach((g) => assert.ok(g === 'wp' || /^WPCAP_BLOCK_/.test(g), 'window.' + g + ' is allowed'));
		// apiVersion 3: exactly one element carries the props from useBlockProps(), the preview is inside it.
		const { tree } = loadBlock(c.dir).edit(c.attributes);
		const wrappers = nodes(tree).filter((n) => n.node.props && n.node.props.className === 'block-editor-block');
		assert.strictEqual(wrappers.length, 1, 'exactly one element carries the block props');
		assert.ok(find(wrappers[0].node, 'ServerSideRender').length + find(wrappers[0].node, 'Placeholder').length >= 1, 'preview inside the block wrapper');
	});
}

test('A455: events block without choices data (no inline script) still renders its controls', () => {
	const { tree } = loadBlock('events').edit({ event: 0, occurrence: 0 });
	assert.strictEqual(find(tree, 'SelectControl').length, 1);
	assert.strictEqual(find(tree, 'ServerSideRender').length, 1);
});

/* ------------------------------------------------------------------ A360 */

test('A360: ID attributes of every block are integers and the editor scripts declare their wp.* packages', () => {
	for (const c of CASES) {
		const dir = path.join(BLOCKS, c.dir);
		const json = JSON.parse(fs.readFileSync(path.join(dir, 'block.json'), 'utf8'));
		Object.keys(json.attributes).forEach((name) => {
			assert.notStrictEqual(json.attributes[name].type, 'number', c.name + ': ' + name + ' is "integer", not "number"');
			if (/^(event|occurrence|eventId|resourceId|limit)$/.test(name)) assert.strictEqual(json.attributes[name].type, 'integer', c.name + ': ' + name);
		});
		const asset = fs.readFileSync(path.join(dir, 'editor.asset.php'), 'utf8');
		['wp-components', 'wp-i18n'].forEach((dep) => assert.ok(asset.includes("'" + dep + "'"), c.name + ' depends on ' + dep));
	}
});

/* ------------------------------------------------------------------ A502 */

const CAL_CHOICES = { events: [{ id: 5, label: 'Sommerfest (01.07.2027 19:00, #5)' }, { id: 6, label: 'Kurs (03.07.2027 10:00, #6)' }] };
function textControls(tree) {
	const out = {};
	find(tree, 'TextControl').forEach((n) => { out[n.node.props.label] = n.node.props; });
	return out;
}

test('A502: the calendar block offers the events as a selection instead of an ID field', () => {
	const block = loadBlock('calendar', { WPCAP_BLOCK_CALENDAR: CAL_CHOICES });
	const { tree, changes } = block.edit({ view: 'month', eventId: 6, limit: 50 });
	const s = selects(tree);
	assert.ok(s.Event, 'event select');
	assert.strictEqual(s.Event.value, '6');
	assert.deepStrictEqual(plain(s.Event.options.map((o) => o.value)), ['0', '5', '6']);
	assert.strictEqual(Object.keys(textControls(tree)).length, 0, 'no number field for the event, no limit in the month view');
	s.Event.onChange('5');
	assert.deepStrictEqual(plain(changes), [{ eventId: 5 }]);
	const gone = selects(block.edit({ view: 'month', eventId: 9 }).tree);
	assert.deepStrictEqual(plain(gone.Event.options.filter((o) => o.value === '9').map((o) => o.label)), ['Event #9 (nicht mehr verfügbar)']);
});

test('A502: the list view of the calendar block has a limit (1–200)', () => {
	const json = JSON.parse(fs.readFileSync(path.join(BLOCKS, 'calendar', 'block.json'), 'utf8'));
	assert.deepStrictEqual(plain(json.attributes.limit), { type: 'integer', default: 50, minimum: 1, maximum: 200 });
	const block = loadBlock('calendar', { WPCAP_BLOCK_CALENDAR: CAL_CHOICES });
	const { tree, changes } = block.edit({ view: 'list', eventId: 0, limit: 20 });
	const limit = Object.values(textControls(tree))[0];
	assert.ok(limit, 'limit field in the list view');
	assert.strictEqual(limit.value, '20');
	limit.onChange('500');
	limit.onChange('0');
	limit.onChange('12');
	assert.deepStrictEqual(plain(changes), [{ limit: 200 }, { limit: 50 }, { limit: 12 }]);
});
