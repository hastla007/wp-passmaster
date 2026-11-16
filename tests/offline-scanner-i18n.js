/**
 * Offline scanner translations: every visible source text has a translation in every catalog, no
 * catalog entry is orphaned, and the language selection works (?lang=, remembered, browser, fallback).
 * Run: node tests/offline-scanner-i18n.js
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const dir = path.join(__dirname, '..', 'assets', 'offline-scanner');
const js = fs.readFileSync(path.join(dir, 'app.js'), 'utf8');
const html = fs.readFileSync(path.join(dir, 'index.html'), 'utf8');
const src = fs.readFileSync(path.join(dir, 'i18n.js'), 'utf8');
let failed = 0;
const check = (name, ok) => { console.log((ok ? '  ok   ' : '  FAIL ') + name); if (!ok) { failed++; } };

function load(search, stored, languages) {
	const store = Object.assign({}, stored);
	const win = {};
	vm.runInNewContext(src, {
		window: win, URLSearchParams, Object, String, Array,
		location: { search },
		navigator: { languages, language: languages[0] },
		localStorage: { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); } }
	});
	return { i18n: win.WPCAP_I18N, store };
}

const used = new Set();
for (const m of js.matchAll(/\bt\('((?:[^'\\]|\\.)*)'/g)) { used.add(m[1]); }
// Both markup styles: bare <x data-i18n>Text</x> and <x data-i18n="Text">Text</x> (apply() translates the
// text content); data-i18n-placeholder / data-i18n-title (bare or with a value) translate that attribute.
for (const m of html.matchAll(/data-i18n(?:="[^"]*")?>([^<]+)</g)) { used.add(m[1].trim()); }
for (const m of html.matchAll(/data-i18n="([^"]+)"/g)) { used.add(m[1].trim()); }
for (const m of html.matchAll(/<[^>]*\bdata-i18n-(placeholder|title)\b[^>]*>/g)) {
	const attr = m[0].match(new RegExp('\\s' + m[1] + '="([^"]+)"'));
	if (attr) { used.add(attr[1]); }
}
const rt = js.match(/RESULT_TEXT = \{([\s\S]*?)\};/);
for (const m of rt[1].matchAll(/: '([^']+)'/g)) { used.add(m[1]); }
used.add(html.match(/<title>([^<]+)<\/title>/)[1]);

const catalogs = vm.runInNewContext('(' + src.match(/var CATALOGS = (\{[\s\S]*?\n\t\});/)[1] + ')');
for (const [lang, catalog] of Object.entries(catalogs)) {
	const missing = [...used].filter((k) => !(k in catalog));
	const orphan = Object.keys(catalog).filter((k) => !used.has(k));
	check(`catalog ${lang}: every source text translated` + (missing.length ? ' – missing: ' + missing.join(' | ') : ''), 0 === missing.length);
	check(`catalog ${lang}: no orphaned entries` + (orphan.length ? ' – ' + orphan.join(' | ') : ''), 0 === orphan.length);
	check(`catalog ${lang}: placeholders kept`, Object.entries(catalog).every(([k, v]) => (k.match(/%\d/g) || []).sort().join() === (v.match(/%\d/g) || []).sort().join()));
}
check('no German literal left outside t() in visible texts', !/textContent = '[^']*[äöüÄÖÜß]/.test(js) && !/showResult\('\w+', '[^']/.test(js));
check('html lang is set at runtime', /I18N\.apply\(document\)/.test(js) && /documentElement\.lang = lang/.test(src));

let r = load('?lang=en', {}, ['de-DE']);
check('?lang=en wins over the browser and is remembered', 'en' === r.i18n.lang && 'en' === r.store.wpcap_scanner_lang && 'Pair' === r.i18n.t('Koppeln'));
r = load('', { wpcap_scanner_lang: 'en' }, ['de-DE']);
check('a remembered language is used offline without ?lang', 'en' === r.i18n.lang);
r = load('', {}, ['fr-FR', 'en-US']);
check('the first supported browser language is used', 'en' === r.i18n.lang && 'en-GB' === r.i18n.locale);
r = load('?lang=xx', {}, ['fr-FR']);
check('unsupported languages fall back to German', 'de' === r.i18n.lang && 'Koppeln' === r.i18n.t('Koppeln') && 'de-DE' === r.i18n.locale);
// The merged catalog uses printf-style keys ('%d offen'); the bare %1 … style still works.
const en = load('?lang=en', {}, ['en']).i18n;
check('placeholders are filled', '3 pending' === en.t('%d offen', 3) && 'Order 7' === en.t('Bestellung %1', 7) && 'Checked in: 2 / 5' === en.t('Eingecheckt: %1 / %2', 2, 5) && '%d pending' === en.t('%d offen'));

console.log(failed ? `${failed} failed` : 'all passed');
process.exit(failed ? 1 : 0);
