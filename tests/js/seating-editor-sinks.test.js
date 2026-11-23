/**
 * A658: the seat-map editor (assets/seating/editor.js) and the seat picker (assets/seating/picker.js)
 * have no HTML sinks. Names, labels and colours of a map reach the DOM only as text or as values of
 * attributes named in the script; a colour that is no #rrggbb value is replaced, event-handler and
 * link attributes are never set from the helpers.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { load } = require('./lib/load');

const ROOT = path.join(__dirname, '..', '..');
const HOSTILE = '<img src=x onerror=alert(1)>';

function editor(draft) {
	return load('assets/seating/editor.js', {
		setup(doc, h) {
			const root = h('div#wpcap-seat-editor', { 'data-draft': JSON.stringify(draft), 'data-i18n': '{}', 'data-locked': '{}' });
			doc.body.appendChild(root);
			doc.body.appendChild(h('form#wpcap-seat-editor-form', [h('input#wpcap-seat-editor-draft', { type: 'hidden' })]));
		},
	});
}

test('A658: no HTML sinks in the seating scripts', () => {
	for (const file of ['assets/seating/editor.js', 'assets/seating/picker.js']) {
		const src = fs.readFileSync(path.join(ROOT, file), 'utf8');
		assert.ok(!/\.(innerHTML|outerHTML)\s*=|insertAdjacentHTML|document\.write|\beval\s*\(|new Function\s*\(|DOMParser|createContextualFragment/.test(src), file + ' has no HTML sink');
	}
});

test('A658: hostile names and colours of a map stay text / are replaced', () => {
	const env = editor({
		width: 400, height: 300,
		zones: [{ key: 'z', name: HOSTILE, color: 'red" onload="alert(1)' }],
		categories: [{ key: 'c', name: HOSTILE, color: 'url(javascript:alert(1))' }],
		groups: [{ key: 'g', type: 'standing', label: HOSTILE, x: 100, y: 100, w: 80, h: 60, capacity: 5, zone: 'z', category: 'c' }],
		seats: [{ key: 's1', row: HOSTILE, label: '<script>x</script>', zone: 'z', category: 'c', x: 50, y: 50, group: '' }],
	});
	const all = env.document.querySelectorAll('*');
	assert.equal(all.filter((e) => ['img', 'script'].includes(e.localName)).length, 0, 'no element built from data');
	const painted = all.filter((e) => e.localName === 'circle' || e.localName === 'rect');
	assert.ok(painted.length > 0, 'the map is drawn');
	painted.forEach((e) => {
		['fill', 'stroke'].forEach((a) => { const v = e.getAttribute(a); if (v !== null) assert.match(v, /^#[0-9a-f]{6}$/i, a + ' is a plain colour'); });
	});
	all.forEach((e) => (e.attributes || []).forEach((a) => assert.ok(!/^on/i.test(a.name), 'no event handler attribute ' + a.name)));
	assert.ok(env.document.body.textContent.includes('<script>x</script>') || env.document.body.textContent.includes(HOSTILE), 'data is shown as text');
});

test('A658: a valid colour is kept', () => {
	const env = editor({ width: 400, height: 300, zones: [{ key: 'z', name: 'Z', color: '#2271b1' }], categories: [{ key: 'c', name: 'K', color: '#00a32a' }], groups: [], seats: [{ key: 's1', row: 'A', label: '1', zone: 'z', category: 'c', x: 50, y: 50, group: '' }] });
	const circle = env.document.querySelectorAll('circle')[0];
	assert.equal(circle.getAttribute('fill'), '#00a32a');
	assert.equal(circle.getAttribute('stroke'), '#2271b1');
});
