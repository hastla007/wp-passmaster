/**
 * Small fake DOM for the browser-script tests (no jsdom in the dev tooling): elements with
 * attributes, dataset, children, events with bubbling, focus, a simple form validity model and a
 * selector engine for the selectors the scripts use (tag, #id, .class, [attr], [attr="v"],
 * [attr^="v"], :checked, :not(…), descendant combinator, selector lists).
 */
'use strict';

const DATA = /^data-/;
const kebab = (key) => key.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase());
const camel = (name) => name.replace(/-([a-z])/g, (m, c) => c.toUpperCase());

class FakeEvent {
	constructor(type, init) {
		this.type = type;
		this.bubbles = !!(init && init.bubbles);
		this.detail = init && init.detail !== undefined ? init.detail : null;
		this.defaultPrevented = false;
		this.target = null;
		this.key = init && init.key;
	}
	preventDefault() { this.defaultPrevented = true; }
	stopPropagation() { this.stopped = true; }
}
class FakeCustomEvent extends FakeEvent {}

/** Splits "a, b" at top-level commas (not inside brackets or parentheses). */
function splitTop(text, sep) {
	const out = [];
	let depth = 0, quote = '', current = '';
	for (const ch of text) {
		if (quote) { if (ch === quote) quote = ''; current += ch; continue; }
		if (ch === '"' || ch === "'") { quote = ch; current += ch; continue; }
		if (ch === '[' || ch === '(') depth++;
		if (ch === ']' || ch === ')') depth--;
		if (depth === 0 && (sep === ' ' ? /\s/.test(ch) : ch === sep)) { if (current.trim()) out.push(current.trim()); current = ''; continue; }
		current += ch;
	}
	if (current.trim()) out.push(current.trim());
	return out;
}

/** Parses one compound selector ("input.a[name="x"]:not([open])") into a predicate. */
function compound(text) {
	const tests = [];
	let rest = text;
	const tag = /^([a-zA-Z][a-zA-Z0-9-]*|\*)/.exec(rest);
	if (tag) { const name = tag[1].toUpperCase(); if (name !== '*') tests.push((n) => n.tagName === name); rest = rest.slice(tag[1].length); }
	while (rest) {
		let m;
		if ((m = /^#([\w-]+)/.exec(rest))) { const id = m[1]; tests.push((n) => n.id === id); }
		else if ((m = /^\.([\w-]+)/.exec(rest))) { const cls = m[1]; tests.push((n) => n.classList.contains(cls)); }
		else if ((m = /^\[([\w-]+)(?:([\^]?=)"([^"]*)")?\]/.exec(rest))) {
			const name = m[1], op = m[2], value = m[3];
			tests.push((n) => {
				const v = n.getAttribute(name);
				if (v === null) return false;
				if (!op) return true;
				return op === '=' ? v === value : v.indexOf(value) === 0;
			});
		} else if ((m = /^:checked/.exec(rest))) { tests.push((n) => !!n.checked); }
		else if ((m = /^:not\(([^()]*(?:\([^()]*\))?[^()]*)\)/.exec(rest))) { const inner = compound(m[1]); tests.push((n) => !inner(n)); }
		else throw new Error('fake-dom: unsupported selector part "' + rest + '" in "' + text + '"');
		rest = rest.slice(m[0].length);
	}
	return (n) => n instanceof FakeElement && tests.every((fn) => fn(n));
}

function matcher(selector) {
	const alternatives = splitTop(selector, ',').map((alt) => splitTop(alt, ' ').map(compound));
	// Like the browser: the whole ancestor chain counts, not only the part inside the queried scope.
	return (node) => alternatives.some((parts) => {
		if (!parts[parts.length - 1](node)) return false;
		let i = parts.length - 2;
		for (let anc = node.parentNode; i >= 0 && anc; anc = anc.parentNode) {
			if (parts[i](anc)) i--;
		}
		return i < 0;
	});
}

class ClassList {
	constructor(el) { this.el = el; }
	list() { return (this.el.getAttribute('class') || '').split(/\s+/).filter(Boolean); }
	contains(c) { return this.list().indexOf(c) !== -1; }
	add(...cs) { const l = this.list(); cs.forEach((c) => { if (l.indexOf(c) === -1) l.push(c); }); this.el.setAttribute('class', l.join(' ')); }
	remove(...cs) { this.el.setAttribute('class', this.list().filter((c) => cs.indexOf(c) === -1).join(' ')); }
	toggle(c, force) { const on = force === undefined ? !this.contains(c) : force; if (on) this.add(c); else this.remove(c); return on; }
}

class FakeNode {
	constructor(doc) { this.ownerDocument = doc; this.parentNode = null; this.children = []; this.listeners = {}; }
	get childNodes() { return this.children; }
	get firstChild() { return this.children[0] || null; }
	appendChild(child) {
		if (child.parentNode) child.parentNode.removeChild(child);
		child.parentNode = this;
		this.children.push(child);
		return child;
	}
	insertBefore(child, ref) {
		if (!ref) return this.appendChild(child);
		if (child.parentNode) child.parentNode.removeChild(child);
		const i = this.children.indexOf(ref);
		child.parentNode = this;
		this.children.splice(i < 0 ? this.children.length : i, 0, child);
		return child;
	}
	removeChild(child) {
		const i = this.children.indexOf(child);
		if (i >= 0) this.children.splice(i, 1);
		child.parentNode = null;
		return child;
	}
	contains(node) { for (let n = node; n; n = n.parentNode) { if (n === this) return true; } return false; }
	addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
	removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn); }
	dispatchEvent(event) { event.target = event.target || this; (this.listeners[event.type] || []).slice().forEach((fn) => fn.call(this, event)); return !event.defaultPrevented; }
	descendants() {
		const out = [];
		const walk = (n) => n.children.forEach((c) => { if (c instanceof FakeElement) { out.push(c); walk(c); } });
		walk(this);
		return out;
	}
	querySelectorAll(selector) { const m = matcher(selector); return this.descendants().filter((n) => m(n)); }
	querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
	get textContent() { return this.children.map((c) => c.textContent).join(''); }
	set textContent(value) { this.children.forEach((c) => { c.parentNode = null; }); this.children = []; if (value !== '' && value !== null && value !== undefined) this.appendChild(this.ownerDocument.createTextNode(String(value))); }
}

class FakeText extends FakeNode {
	constructor(doc, text) { super(doc); this.data = String(text); }
	get textContent() { return this.data; }
	set textContent(v) { this.data = String(v); }
}

const BOOL_ATTRS = ['required', 'hidden', 'disabled', 'open'];
const STRING_ATTRS = ['id', 'type', 'name', 'min', 'max', 'href', 'placeholder'];

class FakeElement extends FakeNode {
	constructor(doc, tag) {
		super(doc);
		this.tagName = String(tag).toUpperCase();
		this.attrs = {};
		this._value = null;
		this.checked = false;
		const self = this;
		this.classList = new ClassList(this);
		this.style = {};
		this.dataset = new Proxy({}, {
			get: (o, k) => (typeof k === 'string' ? (self.getAttribute('data-' + kebab(k)) ?? undefined) : undefined),
			set: (o, k, v) => { self.setAttribute('data-' + kebab(k), v); return true; },
			deleteProperty: (o, k) => { self.removeAttribute('data-' + kebab(k)); return true; },
			has: (o, k) => self.getAttribute('data-' + kebab(k)) !== null,
			ownKeys: () => Object.keys(self.attrs).filter((a) => DATA.test(a)).map((a) => camel(a.slice(5))),
			getOwnPropertyDescriptor: (o, k) => (self.getAttribute('data-' + kebab(k)) !== null ? { enumerable: true, configurable: true, value: self.getAttribute('data-' + kebab(k)) } : undefined),
		});
	}
	setAttribute(name, value) { this.attrs[name] = String(value); if (name === 'checked') this.checked = true; }
	getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; }
	hasAttribute(name) { return this.getAttribute(name) !== null; }
	removeAttribute(name) { delete this.attrs[name]; }
	get className() { return this.getAttribute('class') || ''; }
	set className(v) { this.setAttribute('class', v); }
	get value() { return this._value !== null ? this._value : (this.tagName === 'SELECT' ? this.selectValue() : (this.getAttribute('value') || (this.type === 'checkbox' ? 'on' : ''))); }
	set value(v) { this._value = String(v); }
	selectValue() { const o = this.querySelectorAll('option'); const s = o.filter((x) => x.selected)[0] || o[0]; return s ? s.value : ''; }
	get form() { return this.closest('form'); }
	get nextElementSibling() { const p = this.parentNode; if (!p) return null; const sib = p.children.filter((c) => c instanceof FakeElement); return sib[sib.indexOf(this) + 1] || null; }
	get innerHTML() { return ''; }
	set innerHTML(v) { if (v !== '') throw new Error('fake-dom: innerHTML only supports ""'); this.textContent = ''; }
	get validity() {
		if (['INPUT', 'TEXTAREA', 'SELECT'].indexOf(this.tagName) === -1) return undefined;
		const value = this.value;
		const valueMissing = this.required && (this.type === 'checkbox' ? !this.checked : value === '');
		const typeMismatch = this.type === 'email' && value !== '' && !/^[^@\s]+@[^@\s.]+(\.[^@\s.]+)*$/.test(value);
		return { valueMissing, typeMismatch, valid: !valueMissing && !typeMismatch };
	}
	checkValidity() { const v = this.validity; return !v || v.valid; }
	matches(selector) { return matcher(selector)(this); }
	closest(selector) { const m = matcher(selector); for (let n = this; n instanceof FakeElement; n = n.parentNode) { if (m(n)) return n; } return null; }
	remove() { if (this.parentNode) this.parentNode.removeChild(this); }
	insertAdjacentElement(where, node) {
		if (where !== 'afterend') throw new Error('fake-dom: insertAdjacentElement ' + where);
		const p = this.parentNode;
		const i = p.children.indexOf(this);
		if (node.parentNode) node.parentNode.removeChild(node);
		node.parentNode = p;
		p.children.splice(i + 1, 0, node);
		return node;
	}
	focus() { this.ownerDocument.activeElement = this; }
	blur() { if (this.ownerDocument.activeElement === this) this.ownerDocument.activeElement = this.ownerDocument.body; }
	click() { const e = new FakeEvent('click', { bubbles: true }); this.dispatchEvent(e); }
	scrollIntoView() {}
	reset() {
		this.querySelectorAll('input, textarea, select').forEach((n) => { n._value = null; n.checked = n.hasAttribute('checked'); });
		this.dispatchEvent(new FakeEvent('reset', { bubbles: true }));
	}
	compareDocumentPosition(other) {
		const all = this.ownerDocument.descendants();
		return all.indexOf(other) > all.indexOf(this) ? 4 : 2;
	}
}
BOOL_ATTRS.forEach((name) => {
	Object.defineProperty(FakeElement.prototype, name, {
		get() { return this.hasAttribute(name); },
		set(v) { if (v) this.setAttribute(name, ''); else this.removeAttribute(name); },
	});
});
STRING_ATTRS.forEach((name) => {
	Object.defineProperty(FakeElement.prototype, name, {
		get() { return this.getAttribute(name) || ''; },
		set(v) { this.setAttribute(name, v); },
	});
});

class FakeDocument extends FakeNode {
	constructor() {
		super(null);
		this.ownerDocument = this;
		this.documentElement = new FakeElement(this, 'html');
		this.appendChild(this.documentElement);
		this.body = new FakeElement(this, 'body');
		this.documentElement.appendChild(this.body);
		this.activeElement = this.body;
		this.hidden = false;
		this.readyState = 'complete';
	}
	createElement(tag) { return new FakeElement(this, tag); }
	createElementNS(ns, tag) { return new FakeElement(this, tag); }
	createTextNode(text) { return new FakeText(this, text); }
	getElementById(id) { return this.descendants().filter((n) => n.id === id)[0] || null; }
}

// Bubbling from an element ends at the document.
FakeElement.prototype.dispatchEvent = function (event) {
	event.target = event.target || this;
	let n = this;
	while (n) {
		event.currentTarget = n;
		(n.listeners[event.type] || []).slice().forEach((fn) => fn.call(n, event));
		if (event.stopped || !event.bubbles) break;
		n = n.parentNode;
	}
	return !event.defaultPrevented;
};

/**
 * Builds an element tree: h('form', { class: 'x' }, [h('input', { name: 'email' })]).
 * Children may be strings (text nodes).
 */
function builder(doc) {
	return function h(tag, attrs, children) {
		const node = doc.createElement(tag);
		Object.keys(attrs || {}).forEach((k) => { if (attrs[k] !== false && attrs[k] !== null && attrs[k] !== undefined) node.setAttribute(k, attrs[k] === true ? '' : attrs[k]); });
		(children || []).forEach((c) => node.appendChild(typeof c === 'string' ? doc.createTextNode(c) : c));
		return node;
	};
}

/** Fake timers: run(ms) advances the clock and fires due callbacks in order. */
function timers() {
	let clock = 0, seq = 0;
	const queue = [];
	return {
		setTimeout(fn, ms) { const id = ++seq; queue.push({ id, at: clock + (Number(ms) || 0), fn }); return id; },
		clearTimeout(id) { const i = queue.findIndex((q) => q.id === id); if (i >= 0) queue.splice(i, 1); },
		setInterval(fn, ms) { const id = ++seq; const tick = () => { fn(); queue.push({ id, at: clock + ms, fn: tick }); }; queue.push({ id, at: clock + ms, fn: tick }); return id; },
		clearInterval(id) { this.clearTimeout(id); },
		run(ms) {
			const until = clock + ms;
			for (;;) {
				queue.sort((a, b) => a.at - b.at || a.id - b.id);
				if (!queue.length || queue[0].at > until) break;
				const next = queue.shift();
				clock = next.at;
				next.fn();
			}
			clock = until;
		},
	};
}

module.exports = { FakeDocument, FakeElement, FakeEvent, FakeCustomEvent, builder, timers };
