/**
 * A303: assets/blocks/legacy-names.js registers the former block names wp-pass-captain/events and
 * wp-pass-captain/calendar as hidden blocks that the editor replaces with pass-captain/events and
 * pass-captain/calendar (same attributes), and offers a manual transform to the current name.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SCRIPT = path.join(__dirname, '..', '..', 'assets', 'blocks', 'legacy-names.js');
const plain = (v) => JSON.parse(JSON.stringify(v));

function load() {
	const registered = {};
	const replaced = [];
	const wp = {
		blocks: {
			registerBlockType: (name, settings) => { registered[name] = settings; return settings; },
			createBlock: (name, attributes) => ({ name, attributes }),
		},
		element: { createElement: (type, props, ...children) => ({ type, props: props || {}, children }), useEffect: (fn) => fn() },
		blockEditor: { useBlockProps: () => ({ className: 'block-editor-block' }) },
		data: { dispatch: (store) => (store === 'core/block-editor' ? { replaceBlocks: (id, block) => replaced.push({ id, block }) } : null) },
		i18n: { __: (s) => s },
	};
	const sandbox = { wp };
	sandbox.window = sandbox;
	vm.createContext(sandbox);
	vm.runInContext(fs.readFileSync(SCRIPT, 'utf8'), sandbox);
	return { registered, replaced };
}

test('A303: both former block names are registered, hidden in the inserter, dynamic', () => {
	const { registered } = load();
	assert.deepStrictEqual(Object.keys(registered).sort(), ['wp-pass-captain/calendar', 'wp-pass-captain/events']);
	for (const name of Object.keys(registered)) {
		assert.strictEqual(registered[name].supports.inserter, false, name + ' is not offered in the inserter');
		assert.strictEqual(registered[name].save(), null, name + ' is dynamic');
	}
});

test('A303: opening a post replaces a former block by the current name with the same attributes', () => {
	const { registered, replaced } = load();
	registered['wp-pass-captain/events'].edit({ clientId: 'c1', attributes: { event: 5, occurrence: 7, layout: 'list' } });
	registered['wp-pass-captain/calendar'].edit({ clientId: 'c2', attributes: { view: 'list', eventId: 3 } });
	assert.deepStrictEqual(plain(replaced), [
		{ id: 'c1', block: { name: 'pass-captain/events', attributes: { event: 5, occurrence: 7, layout: 'list' } } },
		{ id: 'c2', block: { name: 'pass-captain/calendar', attributes: { view: 'list', eventId: 3 } } },
	]);
});

test('A303: manual transform to the current block keeps the attributes', () => {
	const { registered } = load();
	const to = registered['wp-pass-captain/events'].transforms.to[0];
	assert.deepStrictEqual(plain(to.blocks), ['pass-captain/events']);
	assert.deepStrictEqual(plain(to.transform({ event: 9 })), { name: 'pass-captain/events', attributes: { event: 9 } });
});

test('A303: the current block.json names have no wp- prefix', () => {
	for (const dir of ['events', 'calendar']) {
		const json = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'blocks', dir, 'block.json'), 'utf8'));
		assert.strictEqual(json.name, 'pass-captain/' + dir);
	}
});
