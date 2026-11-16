'use strict';
/*
 * Minimal DOM for unit tests of the plugin's browser scripts (no jsdom dependency).
 * Supports what assets/*.js use: elements, text nodes, attributes, dataset, classList, events with
 * bubbling, focus, form controls with a small validity model, and querySelector(All)/closest/matches
 * for compound selectors (tag, #id, .class, [attr], [attr="v"], [attr^="v"], :checked, :not(...)),
 * descendant (space) and child (>) combinators and comma lists.
 */

const BOOL_PROPS = ['checked', 'disabled', 'hidden', 'required', 'selected', 'muted'];
const STRING_PROPS = { id: 'id', className: 'class', name: 'name', type: 'type', max: 'max', min: 'min', placeholder: 'placeholder', src: 'src', href: 'href', htmlFor: 'for' };

class Event {
	constructor(type, init) {
		init = init || {};
		this.type = type;
		this.bubbles = !!init.bubbles;
		this.cancelable = init.cancelable !== false;
		this.defaultPrevented = false;
		this.target = null;
		this.currentTarget = null;
		this.key = init.key;
		this.detail = init.detail;
		this._stop = false;
	}
	preventDefault() { if (this.cancelable) this.defaultPrevented = true; }
	stopPropagation() { this._stop = true; }
	stopImmediatePropagation() { this._stop = true; this._stopNow = true; }
}
class CustomEvent extends Event {
	constructor(type, init) { super(type, init); this.detail = init && init.detail; }
}

class EventTarget {
	constructor() { this._listeners = {}; }
	addEventListener(type, fn, opts) {
		(this._listeners[type] = this._listeners[type] || []).push({ fn, once: !!(opts && opts.once) });
	}
	removeEventListener(type, fn) {
		this._listeners[type] = (this._listeners[type] || []).filter((l) => l.fn !== fn);
	}
	dispatchEvent(event) {
		if (!event.target) event.target = this;
		let node = this;
		while (node) {
			const list = (node._listeners[event.type] || []).slice();
			event.currentTarget = node;
			for (const l of list) {
				if (event._stopNow) break;
				if (l.once) node.removeEventListener(event.type, l.fn);
				l.fn.call(node, event);
			}
			if (!event.bubbles || event._stop) break;
			node = node.parentNode || (node.nodeType === 9 ? node.defaultView : null);
		}
		return !event.defaultPrevented;
	}
}

class Node extends EventTarget {
	constructor(doc) { super(); this.ownerDocument = doc; this.parentNode = null; this.childNodes = []; }
	get children() { return this.childNodes.filter((n) => n.nodeType === 1); }
	get firstChild() { return this.childNodes[0] || null; }
	get textContent() { return this.childNodes.map((n) => n.textContent).join(''); }
	set textContent(v) {
		this.childNodes.forEach((n) => { n.parentNode = null; });
		this.childNodes = [];
		if (v !== '' && v !== null && v !== undefined) this.appendChild(this.ownerDocument.createTextNode(String(v)));
	}
	appendChild(child) { return this.insertBefore(child, null); }
	insertBefore(child, ref) {
		if (child.parentNode) child.parentNode.removeChild(child);
		const i = ref ? this.childNodes.indexOf(ref) : -1;
		if (i < 0) this.childNodes.push(child); else this.childNodes.splice(i, 0, child);
		child.parentNode = this;
		return child;
	}
	removeChild(child) {
		const i = this.childNodes.indexOf(child);
		if (i >= 0) this.childNodes.splice(i, 1);
		child.parentNode = null;
		return child;
	}
	remove() { if (this.parentNode) this.parentNode.removeChild(this); }
	contains(other) { for (let n = other; n; n = n.parentNode) { if (n === this) return true; } return false; }
	/** Document order index (for compareDocumentPosition). */
	_order() {
		const path = [];
		for (let n = this; n.parentNode; n = n.parentNode) path.unshift(n.parentNode.childNodes.indexOf(n));
		return path;
	}
	compareDocumentPosition(other) {
		const a = this._order(), b = other._order();
		for (let i = 0; i < Math.max(a.length, b.length); i++) {
			const x = a[i] === undefined ? -1 : a[i], y = b[i] === undefined ? -1 : b[i];
			if (x !== y) return y > x ? 4 : 2; // FOLLOWING : PRECEDING
		}
		return 0;
	}
}
Node.ELEMENT_NODE = 1;
Node.TEXT_NODE = 3;
Node.DOCUMENT_POSITION_PRECEDING = 2;
Node.DOCUMENT_POSITION_FOLLOWING = 4;

class Text extends Node {
	constructor(doc, text) { super(doc); this.nodeType = 3; this.data = text; }
	get textContent() { return this.data; }
	set textContent(v) { this.data = String(v); }
}

class Element extends Node {
	constructor(doc, tag, ns) {
		super(doc);
		this.nodeType = 1;
		this.tagName = ns ? tag : tag.toUpperCase();
		this.namespaceURI = ns || 'http://www.w3.org/1999/xhtml';
		this.attributes = new Map();
		this._value = null;
		this.style = {};
		const self = this;
		this.dataset = new Proxy({}, {
			get(_, k) { return typeof k === 'string' ? self.getAttribute('data-' + k.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())) ?? undefined : undefined; },
			set(_, k, v) { self.setAttribute('data-' + k.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase()), String(v)); return true; },
			deleteProperty(_, k) { self.removeAttribute('data-' + k.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())); return true; },
			has(_, k) { return self.hasAttribute('data-' + k.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())); },
		});
		this.classList = {
			contains: (c) => (self.getAttribute('class') || '').split(/\s+/).includes(c),
			add: (...cs) => { const s = new Set((self.getAttribute('class') || '').split(/\s+/).filter(Boolean)); cs.forEach((c) => s.add(c)); self.setAttribute('class', [...s].join(' ')); },
			remove: (...cs) => { self.setAttribute('class', (self.getAttribute('class') || '').split(/\s+/).filter((c) => c && !cs.includes(c)).join(' ')); },
			toggle: (c, force) => { const on = force === undefined ? !self.classList.contains(c) : force; if (on) self.classList.add(c); else self.classList.remove(c); return on; },
		};
	}
	get localName() { return this.tagName.toLowerCase(); }
	getAttribute(k) { return this.attributes.has(k) ? this.attributes.get(k) : null; }
	setAttribute(k, v) { this.attributes.set(k, String(v)); }
	removeAttribute(k) { this.attributes.delete(k); }
	hasAttribute(k) { return this.attributes.has(k); }
	get value() {
		if (this.localName === 'select') {
			const opts = this.querySelectorAll('option');
			const sel = opts.find((o) => o.selected) || opts[0];
			return sel ? sel.value : '';
		}
		if (this.localName === 'option' && this._value === null && !this.hasAttribute('value')) return this.textContent;
		if (this._value !== null) return this._value;
		if (this.localName === 'textarea') return this.textContent;
		return this.getAttribute('value') ?? (this.type === 'checkbox' || this.type === 'radio' ? 'on' : '');
	}
	set value(v) {
		if (this.localName === 'select') { this.querySelectorAll('option').forEach((o) => { o.selected = o.value === String(v); }); return; }
		this._value = String(v);
	}
	get form() { return this.closest('form'); }
	get elements() {
		const list = this.querySelectorAll('input, select, textarea, button');
		const named = {};
		list.forEach((el) => { if (el.name && !(el.name in named)) named[el.name] = el; });
		return Object.assign(list, named);
	}
	get labels() { return this.id ? this.ownerDocument.querySelectorAll('label[for="' + this.id + '"]') : []; }
	get nextElementSibling() {
		if (!this.parentNode) return null;
		const sibs = this.parentNode.children;
		return sibs[sibs.indexOf(this) + 1] || null;
	}
	get innerHTML() { return this.textContent; }
	set innerHTML(v) { if (v !== '') throw new Error('mini DOM: only innerHTML = "" is supported'); this.textContent = ''; }
	get validity() {
		const v = this.value;
		const empty = this.type === 'checkbox' || this.type === 'radio' ? !this.checked : String(v).trim() === '';
		const valueMissing = this.required && empty;
		const typeMismatch = !empty && this.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v).trim()); // browsers strip the whitespace of e-mail values
		let rangeOverflow = false, rangeUnderflow = false;
		if (this.type === 'number' && !empty) {
			if (this.hasAttribute('max') && Number(v) > Number(this.max)) rangeOverflow = true;
			if (this.hasAttribute('min') && Number(v) < Number(this.min)) rangeUnderflow = true;
		}
		return { valueMissing, typeMismatch, rangeOverflow, rangeUnderflow, valid: !valueMissing && !typeMismatch && !rangeOverflow && !rangeUnderflow };
	}
	get validationMessage() { return this.validity.valid ? '' : 'invalid'; }
	checkValidity() { return this.querySelectorAll('input, select, textarea').concat(this.localName === 'form' ? [] : [this]).every((el) => el.disabled || !('validity' in el) || el.validity.valid); }
	reportValidity() { return this.localName === 'form' ? this.querySelectorAll('input, select, textarea').every((el) => el.disabled || el.validity.valid) : this.validity.valid; }
	focus() { this.ownerDocument.activeElement = this; }
	blur() { if (this.ownerDocument.activeElement === this) this.ownerDocument.activeElement = this.ownerDocument.body; }
	click() { this.dispatchEvent(new Event('click', { bubbles: true })); }
	scrollIntoView() {}
	reset() {
		this.querySelectorAll('input, textarea, select').forEach((el) => {
			el._value = null;
			if (el.type === 'checkbox' || el.type === 'radio') el.checked = el.hasAttribute('data-default-checked');
		});
	}
	insertAdjacentElement(where, el) {
		if (where === 'afterend') this.parentNode.insertBefore(el, this.nextElementSibling);
		else if (where === 'beforeend') this.appendChild(el);
		else if (where === 'beforebegin') this.parentNode.insertBefore(el, this);
		else if (where === 'afterbegin') this.insertBefore(el, this.firstChild);
		return el;
	}
	querySelectorAll(sel) {
		const groups = parseSelector(sel);
		const out = [];
		walk(this, (el) => { if (groups.some((g) => matchChain(el, g, this))) out.push(el); });
		return out;
	}
	querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
	matches(sel) { return parseSelector(sel).some((g) => matchChain(this, g, null)); }
	closest(sel) { for (let n = this; n && n.nodeType === 1; n = n.parentNode) { if (n.matches(sel)) return n; } return null; }
	showModal() { this.open = true; }
	close(v) { this.open = false; if (v !== undefined) this.returnValue = v; this.dispatchEvent(new Event('close')); }
	getContext() { return null; }
}
BOOL_PROPS.forEach((p) => Object.defineProperty(Element.prototype, p, {
	get() { return this['_' + p] !== undefined ? this['_' + p] : this.hasAttribute(p); },
	set(v) { this['_' + p] = !!v; },
}));
Object.keys(STRING_PROPS).forEach((p) => Object.defineProperty(Element.prototype, p, {
	get() { const v = this.getAttribute(STRING_PROPS[p]); return v === null ? (p === 'type' && this.localName === 'input' ? 'text' : (p === 'type' && this.localName === 'button' ? 'submit' : '')) : v; },
	set(v) { this.setAttribute(STRING_PROPS[p], v); },
}));

function walk(root, fn) { root.childNodes.forEach((n) => { if (n.nodeType === 1) { fn(n); walk(n, fn); } }); }

/* ---------------- selectors ---------------- */

function splitTop(s, sep) {
	const out = []; let depth = 0, quote = null, cur = '';
	for (const ch of s) {
		if (quote) { if (ch === quote) quote = null; cur += ch; continue; }
		if (ch === '"' || ch === "'") { quote = ch; cur += ch; continue; }
		if (ch === '(' || ch === '[') depth++;
		if (ch === ')' || ch === ']') depth--;
		if (ch === sep && depth === 0) { out.push(cur); cur = ''; continue; }
		cur += ch;
	}
	out.push(cur);
	return out;
}
const cache = new Map();
function parseSelector(sel) {
	if (cache.has(sel)) return cache.get(sel);
	const groups = splitTop(sel, ',').map((g) => {
		// Tokenize into compounds and combinators.
		const parts = [];
		let rest = g.trim().replace(/\s*>\s*/g, ' > ');
		splitTop(rest, ' ').filter(Boolean).forEach((tok) => parts.push(tok === '>' ? '>' : parseCompound(tok)));
		return parts;
	});
	cache.set(sel, groups);
	return groups;
}
function parseCompound(tok) {
	const c = { tag: null, ids: [], classes: [], attrs: [], pseudos: [] };
	const re = /^(\*|[a-zA-Z][\w-]*)|#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:([\^$*]?=)(?:"([^"]*)"|'([^']*)'|([^\]]*)))?\]|:(not)\(((?:[^()]|\([^()]*\))*)\)|:([\w-]+)/g;
	let m, pos = 0;
	while ((m = re.exec(tok)) && m.index === pos) {
		pos = re.lastIndex;
		if (m[1]) c.tag = m[1] === '*' ? null : m[1].toLowerCase();
		else if (m[2]) c.ids.push(m[2]);
		else if (m[3]) c.classes.push(m[3]);
		else if (m[4]) c.attrs.push({ name: m[4], op: m[5] || null, value: m[6] ?? m[7] ?? m[8] ?? null });
		else if (m[9]) c.pseudos.push({ not: parseSelector(m[10]) });
		else if (m[11]) c.pseudos.push({ name: m[11] });
	}
	if (pos !== tok.length) throw new Error('mini DOM: unsupported selector "' + tok + '"');
	return c;
}
function matchCompound(el, c) {
	if (c.tag && el.localName !== c.tag) return false;
	if (c.ids.some((id) => el.getAttribute('id') !== id)) return false;
	if (c.classes.some((cl) => !el.classList.contains(cl))) return false;
	for (const a of c.attrs) {
		const v = a.name === 'value' && el._value !== null ? el.value : el.getAttribute(a.name);
		if (v === null) return false;
		if (a.op === '=' && v !== a.value) return false;
		if (a.op === '^=' && !v.startsWith(a.value)) return false;
		if (a.op === '$=' && !v.endsWith(a.value)) return false;
		if (a.op === '*=' && !v.includes(a.value)) return false;
	}
	for (const p of c.pseudos) {
		if (p.not && p.not.some((g) => matchChain(el, g, null))) return false;
		if (p.name === 'checked' && !el.checked) return false;
		if (p.name === 'disabled' && !el.disabled) return false;
		if (p.name === 'focus-visible' || p.name === 'focus') { if (el.ownerDocument.activeElement !== el) return false; }
	}
	return true;
}
function matchChain(el, parts, scope) {
	let i = parts.length - 1;
	if (!matchCompound(el, parts[i])) return false;
	let node = el;
	i--;
	while (i >= 0) {
		const child = parts[i] === '>';
		if (child) i--;
		const c = parts[i];
		let found = false;
		for (let p = node.parentNode; p && p.nodeType === 1 && p !== scope; p = child ? null : p.parentNode) {
			if (matchCompound(p, c)) { node = p; found = true; break; }
			if (child) break;
		}
		if (!found) return false;
		i--;
	}
	return true;
}

/* ---------------- document ---------------- */

class Document extends Node {
	constructor() {
		super(null);
		this.ownerDocument = this;
		this.nodeType = 9;
		this.documentElement = new Element(this, 'html');
		this.head = new Element(this, 'head');
		this.body = new Element(this, 'body');
		this.documentElement.appendChild(this.head);
		this.documentElement.appendChild(this.body);
		this.childNodes.push(this.documentElement);
		this.documentElement.parentNode = this;
		this.activeElement = this.body;
		this.readyState = 'complete';
		this.hidden = false;
		this.title = '';
		this.defaultView = null;
	}
	createElement(tag) { return new Element(this, tag); }
	createElementNS(ns, tag) { return new Element(this, tag, ns); }
	createTextNode(text) { return new Text(this, text); }
	getElementById(id) { return this.documentElement.querySelector('#' + id) ; }
	querySelectorAll(sel) { return this.documentElement.querySelectorAll(sel).concat(this.documentElement.matches(sel) ? [this.documentElement] : []); }
	querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}

/**
 * Builds an element tree from a compact spec: h('form.wpcap-checkout-form', {attrs}, [children|'text']).
 * Tag spec: tag(#id)?(.class)*.
 */
function builder(doc) {
	return function h(spec, attrs, children) {
		if (Array.isArray(attrs) || typeof attrs === 'string') { children = attrs; attrs = {}; }
		const tag = /^[a-z0-9]+/i.exec(spec)[0];
		const el = doc.createElement(tag);
		const id = /#([\w-]+)/.exec(spec);
		const classes = (spec.match(/\.[\w-]+/g) || []).map((c) => c.slice(1));
		if (id) el.setAttribute('id', id[1]);
		if (classes.length) el.setAttribute('class', classes.join(' '));
		Object.keys(attrs || {}).forEach((k) => {
			const v = attrs[k];
			if (v === false || v === null || v === undefined) return;
			if (k === 'value' && (el.localName === 'input' || el.localName === 'textarea')) { el.setAttribute('value', v); return; }
			el.setAttribute(k, v === true ? '' : v);
		});
		[].concat(children === undefined ? [] : children).forEach((c) => el.appendChild(typeof c === 'string' ? doc.createTextNode(c) : c));
		if (tag.toLowerCase() === 'form') {
			// form.<name> access to named controls (HTMLFormElement named getter), for the controls present now.
			el.querySelectorAll('[name]').forEach((c) => {
				if (!(c.name in el)) Object.defineProperty(el, c.name, { get: () => el.querySelector('[name="' + c.name + '"]'), configurable: true });
			});
		}
		return el;
	};
}

module.exports = { Document, Element, Node, Text, Event, CustomEvent, EventTarget, builder };
