/**
 * Minimal DOM for the checkout tests (assets/checkout.js in node:vm): elements with attributes,
 * dataset, classList, tree operations, bubbling events and a small selector engine (tag, #id, .class,
 * [attr], [attr="v"], [attr^="v"], :checked, :not(…), descendant combinator, comma lists).
 * Not a browser: only what checkout.js uses.
 */
'use strict';

const REFLECT = ['id', 'name', 'type', 'min', 'max', 'href', 'for'];

function kebab(name) { return name.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase()); }

class FakeEvent {
	constructor(type, init) {
		this.type = type;
		this.bubbles = !!(init && init.bubbles);
		this.detail = init && init.detail;
		this.defaultPrevented = false;
		this.persisted = !!(init && init.persisted);
	}
	preventDefault() { this.defaultPrevented = true; }
}

function splitTop(selector, sep) {
	const out = [];
	let depth = 0, quote = '', cur = '';
	for (const ch of selector) {
		if (quote) { cur += ch; if (ch === quote) quote = ''; continue; }
		if (ch === '"' || ch === "'") { quote = ch; cur += ch; continue; }
		if (ch === '(' || ch === '[') depth++;
		if (ch === ')' || ch === ']') depth--;
		if (depth === 0 && (sep === ' ' ? /\s/.test(ch) : ch === sep)) { if (cur.trim()) out.push(cur.trim()); cur = ''; continue; }
		cur += ch;
	}
	if (cur.trim()) out.push(cur.trim());
	return out;
}

function matchesCompound(node, compound) {
	if (!(node instanceof FakeElement)) return false;
	let rest = compound;
	const tag = /^[a-z][a-z0-9]*/i.exec(rest);
	if (tag) { if (node.tagName !== tag[0].toUpperCase()) return false; rest = rest.slice(tag[0].length); }
	while (rest) {
		let m;
		if ((m = /^#([\w-]+)/.exec(rest))) { if (node.id !== m[1]) return false; }
		else if ((m = /^\.([\w-]+)/.exec(rest))) { if (!node.classList.contains(m[1])) return false; }
		else if ((m = /^\[([\w-]+)(?:(\^?=)"((?:[^"\\]|\\.)*)")?\]/.exec(rest))) {
			const value = node.getAttribute(m[1]);
			if (value === null) return false;
			const wanted = m[3] === undefined ? null : m[3].replace(/\\(.)/g, '$1');
			if (m[2] === '=' && value !== wanted) return false;
			if (m[2] === '^=' && value.indexOf(wanted) !== 0) return false;
		} else if ((m = /^:checked/.exec(rest))) { if (!node.checked) return false; }
		else if ((m = /^:not\((.*?)\)(?=$|[.#[:])/.exec(rest))) { if (matchesCompound(node, m[1])) return false; }
		else throw new Error('fake-dom: unsupported selector part ' + rest);
		rest = rest.slice(m[0].length);
	}
	return true;
}

function matchesSelector(node, selector, scope) {
	return splitTop(selector, ',').some((one) => {
		const parts = splitTop(one, ' ');
		if (!matchesCompound(node, parts[parts.length - 1])) return false;
		let current = node.parentNode;
		for (let i = parts.length - 2; i >= 0; i--) {
			while (current && current !== scope && !matchesCompound(current, parts[i])) current = current.parentNode;
			if (!current || current === scope) return false;
			current = current.parentNode;
		}
		return true;
	});
}

class FakeElement {
	constructor(tag, doc) {
		this.tagName = String(tag).toUpperCase();
		this.ownerDocument = doc;
		this.attrs = {};
		this.children = [];
		this.parentNode = null;
		this.listeners = {};
		this.ownText = '';
		this.value = '';
		this.checked = false;
		this.disabled = false;
		this.required = false;
		this.hidden = false;
		this.open = false;
		const self = this;
		this.dataset = new Proxy({}, {
			get: (t, k) => self.getAttribute('data-' + kebab(String(k))) ?? undefined,
			set: (t, k, v) => { self.setAttribute('data-' + kebab(String(k)), v); return true; },
			deleteProperty: (t, k) => { self.removeAttribute('data-' + kebab(String(k))); return true; },
			has: (t, k) => self.getAttribute('data-' + kebab(String(k))) !== null,
		});
		this.classList = {
			contains: (c) => (self.getAttribute('class') || '').split(/\s+/).includes(c),
			add: (c) => { if (!self.classList.contains(c)) self.setAttribute('class', ((self.getAttribute('class') || '') + ' ' + c).trim()); },
			remove: (c) => { self.setAttribute('class', (self.getAttribute('class') || '').split(/\s+/).filter((x) => x && x !== c).join(' ')); },
		};
		REFLECT.forEach((name) => {
			Object.defineProperty(this, name === 'for' ? 'htmlFor' : name, {
				get: () => self.getAttribute(name) ?? '',
				set: (v) => { self.setAttribute(name, v); },
			});
		});
	}
	setAttribute(name, value) {
		value = String(value);
		if (name === 'checked') this.checked = true;
		if (name === 'required') this.required = true;
		if (name === 'hidden') this.hidden = true;
		if (name === 'value') this.value = value;
		this.attrs[name] = value;
	}
	getAttribute(name) {
		if (name === 'hidden') return this.hidden ? '' : null;
		if (name === 'open') return this.open ? '' : null;
		return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
	}
	hasAttribute(name) { return this.getAttribute(name) !== null; }
	removeAttribute(name) { delete this.attrs[name]; }
	get textContent() { return this.ownText + this.children.map((c) => c.textContent).join(''); }
	set textContent(value) { this.children.forEach((c) => { c.parentNode = null; }); this.children = []; this.ownText = String(value); }
	// HTML is kept as text without tags (enough to check what a script put in); innerHTML reads it back.
	set innerHTML(value) { this.textContent = String(value).replace(/<[^>]*>/g, ''); this.html = String(value); }
	get innerHTML() { return this.html || ''; }
	get firstChild() { return this.children[0] || null; }
	get nextElementSibling() {
		if (!this.parentNode) return null;
		const list = this.parentNode.children;
		return list[list.indexOf(this) + 1] || null;
	}
	get form() { return this.closest('form'); }
	get elements() {
		const out = {};
		this.querySelectorAll('input, select, textarea, button').forEach((n) => { if (n.name && !out[n.name]) out[n.name] = n; });
		return out;
	}
	get validity() {
		const missing = this.required && (this.type === 'checkbox' ? !this.checked : String(this.value).trim() === '');
		const mismatch = this.type === 'email' && this.value !== '' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(this.value);
		return { valid: !missing && !mismatch, valueMissing: missing, typeMismatch: mismatch };
	}
	appendChild(child) {
		if (child.parentNode) child.remove();
		child.parentNode = this;
		this.children.push(child);
		return child;
	}
	insertBefore(child, ref) {
		if (child.parentNode) child.remove();
		child.parentNode = this;
		const index = ref ? this.children.indexOf(ref) : -1;
		if (index < 0) this.children.push(child); else this.children.splice(index, 0, child);
		return child;
	}
	insertAdjacentElement(where, child) {
		if (where !== 'afterend') throw new Error('fake-dom: only afterend');
		const next = this.nextElementSibling;
		this.parentNode.insertBefore(child, next);
		return child;
	}
	remove() {
		if (!this.parentNode) return;
		const list = this.parentNode.children;
		list.splice(list.indexOf(this), 1);
		this.parentNode = null;
	}
	contains(node) { for (let n = node; n; n = n.parentNode) { if (n === this) return true; } return false; }
	descendants() { const out = []; this.children.forEach((c) => { out.push(c); out.push(...c.descendants()); }); return out; }
	querySelectorAll(selector) { return this.descendants().filter((n) => matchesSelector(n, selector, this)); }
	querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
	matches(selector) { return matchesSelector(this, selector, null); }
	closest(selector) { for (let n = this; n instanceof FakeElement; n = n.parentNode) { if (n.matches(selector)) return n; } return null; }
	compareDocumentPosition(other) {
		const all = this.ownerDocument.body.descendants();
		return all.indexOf(other) > all.indexOf(this) ? 4 : 2;
	}
	addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
	dispatchEvent(event) {
		if (!event.target) event.target = this;
		const path = [];
		for (let n = this; n; n = n.parentNode) path.push(n);
		if (event.bubbles || path.length === 1) path.push(this.ownerDocument);
		for (const node of event.bubbles ? path : [this]) {
			(node.listeners[event.type] || []).slice().forEach((fn) => fn.call(node, event));
		}
		return !event.defaultPrevented;
	}
	focus() { this.ownerDocument.activeElement = this; }
	scrollIntoView() {}
	reset() { this.querySelectorAll('input, textarea').forEach((n) => { if (n.type !== 'hidden' && n.type !== 'radio') { n.value = n.attrs.value || ''; n.checked = 'checked' in n.attrs; } }); }
}

function createDocument() {
	const doc = {
		listeners: {},
		activeElement: null,
		addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
		createElement: (tag) => new FakeElement(tag, doc),
		createTextNode: (text) => { const n = new FakeElement('#text', doc); n.ownText = String(text); return n; },
		querySelectorAll: (sel) => doc.body.querySelectorAll(sel),
		querySelector: (sel) => doc.body.querySelector(sel),
		getElementById: (id) => doc.body.descendants().find((n) => n.id === id) || null,
		dispatchEvent(event) { (this.listeners[event.type] || []).slice().forEach((fn) => fn.call(doc, event)); return !event.defaultPrevented; },
	};
	doc.body = new FakeElement('body', doc);
	doc.activeElement = doc.body;
	return doc;
}

/** h('input', { class: 'x', name: 'email' }, [children]) – builds a tree in doc. */
function builder(doc) {
	return function h(tag, attrs, children) {
		const node = doc.createElement(tag);
		Object.keys(attrs || {}).forEach((k) => {
			if (k === 'checked' || k === 'required' || k === 'hidden') { if (attrs[k]) node.setAttribute(k, ''); }
			else node.setAttribute(k, attrs[k]);
		});
		(children || []).forEach((c) => node.appendChild(typeof c === 'string' ? doc.createTextNode(c) : c));
		return node;
	};
}

module.exports = { FakeEvent, FakeElement, createDocument, builder };
