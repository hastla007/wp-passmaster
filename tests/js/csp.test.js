'use strict';
/* FE-8: no inline event handlers; confirm/print/auto-submit behaviours come from external scripts. */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { load, Event, ROOT } = require('./lib/load');

const click = (el) => el.dispatchEvent(new Event('click', { bubbles: true, cancelable: true }));
const submit = (el) => el.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

function admin(confirmAnswer, setup) {
	const asked = [];
	const env = load('assets/admin-confirm.js', {
		window: { confirm: (text) => { asked.push(text); return confirmAnswer; } },
		setup,
	});
	return Object.assign(env, { asked });
}

test('admin: data-wpcap-confirm on a button asks with the native dialog; cancel blocks the click', () => {
	const env = admin(false, (doc, h) => {
		doc.body.appendChild(h('form', [h('button#b', { type: 'submit', 'data-wpcap-confirm': 'Wirklich stornieren?' }, 'Stornieren')]));
	});
	const button = env.document.getElementById('b');
	const allowed = click(button);
	assert.deepEqual(env.asked, ['Wirklich stornieren?']);
	assert.equal(allowed, false, 'cancel prevents the default action (no submit)');
});

test('admin: confirmed button click goes through; buttons without the attribute are not asked', () => {
	const env = admin(true, (doc, h) => {
		doc.body.appendChild(h('form', [
			h('button#yes', { type: 'submit', 'data-wpcap-confirm': 'Erstatten?' }, [h('span#inner', 'Erstatten')]),
			h('button#plain', { type: 'submit' }, 'Speichern'),
		]));
	});
	assert.equal(click(env.document.getElementById('inner')), true, 'click on a child of the button counts for the button');
	assert.deepEqual(env.asked, ['Erstatten?']);
	assert.equal(click(env.document.getElementById('plain')), true);
	assert.equal(env.asked.length, 1);
});

test('admin: data-wpcap-confirm on a form guards its submit', () => {
	const env = admin(false, (doc, h) => {
		doc.body.appendChild(h('form#f', { 'data-wpcap-confirm': 'Kasse jetzt abschließen?' }, [h('button', { type: 'submit' }, 'OK')]));
	});
	assert.equal(submit(env.document.getElementById('f')), false);
	assert.deepEqual(env.asked, ['Kasse jetzt abschließen?']);
	const ok = admin(true, (doc, h) => { doc.body.appendChild(h('form#f', { 'data-wpcap-confirm': 'Sicher?' })); });
	assert.equal(submit(ok.document.getElementById('f')), true);
});

test('admin: select with data-wpcap-autosubmit submits its form on change', () => {
	const env = admin(true, (doc, h) => {
		doc.body.appendChild(h('form#f', [h('select#s', { name: 'occurrence_id', 'data-wpcap-autosubmit': '' }, [h('option', { value: '1' }, 'A'), h('option', { value: '2' }, 'B')])]));
	});
	let submitted = 0;
	env.document.getElementById('f').submit = () => { submitted++; };
	env.document.getElementById('s').dispatchEvent(new Event('change', { bubbles: true }));
	assert.equal(submitted, 1);
});

test('admin: data-wpcap-select-on-focus selects the one-time secret', () => {
	const env = admin(true, (doc, h) => { doc.body.appendChild(h('input#k', { type: 'text', readonly: true, value: 'secret', 'data-wpcap-select-on-focus': '' })); });
	let selected = 0;
	const input = env.document.getElementById('k');
	input.select = () => { selected++; };
	input.dispatchEvent(new Event('focusin', { bubbles: true }));
	assert.equal(selected, 1);
});

test('print: data-wpcap-print button prints, other clicks do not; body data-wpcap-autoprint prints on load', () => {
	let printed = 0;
	const env = load('assets/print.js', {
		window: { print: () => { printed++; } },
		setup(doc, h) { doc.body.appendChild(h('main', [h('button#p', { type: 'button', 'data-wpcap-print': '' }, 'Drucken'), h('a#other', { href: '#' }, 'x')])); },
	});
	click(env.document.getElementById('other'));
	assert.equal(printed, 0);
	click(env.document.getElementById('p'));
	assert.equal(printed, 1);
	env.window.dispatchEvent(new Event('load'));
	assert.equal(printed, 1, 'no auto print without the body attribute');
	env.document.body.setAttribute('data-wpcap-autoprint', '');
	env.window.dispatchEvent(new Event('load'));
	assert.equal(printed, 2);
});

function walk(dir, exts, out) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) { if (entry.name !== 'vendor') walk(full, exts, out); } else if (exts.includes(path.extname(entry.name))) out.push(full);
	}
	return out;
}

test('no inline event handlers in PHP templates, assets or the offline app (strict CSP possible)', () => {
	const files = walk(path.join(ROOT, 'src'), ['.php'], []).concat(walk(path.join(ROOT, 'assets'), ['.html', '.php'], []));
	if (fs.existsSync(path.join(ROOT, 'templates'))) walk(path.join(ROOT, 'templates'), ['.php', '.html'], files);
	const offenders = [];
	for (const file of files) {
		const src = fs.readFileSync(file, 'utf8');
		const re = /<[a-z][^<>]*\son(click|change|submit|load|focus|blur|input|keyup|keydown|error|mouseover)\s*=/gi;
		let m;
		while ((m = re.exec(src))) offenders.push(path.relative(ROOT, file) + ': ' + m[0].slice(0, 80));
		if (/\son(click|change|submit|load|focus)=\\?["']/i.test(src.replace(/data-wpcap-[a-z-]+/g, ''))) offenders.push(path.relative(ROOT, file) + ': handler attribute in a string');
	}
	assert.deepEqual([...new Set(offenders)], []);
});
