'use strict';
/*
 * In-memory IndexedDB subset used by the offline scanner (assets/offline-scanner/app.js): open with
 * onupgradeneeded, object stores with or without keyPath, one index, get/put/getAll/delete/clear and
 * index.getAll(value). Requests and transactions complete asynchronously like the real API.
 * Values are structured-cloned on write and read.
 */
function later(fn) { setImmediate(fn); }
const clone = (v) => (v === undefined ? undefined : structuredClone(v));

class Request { constructor() { this.result = undefined; this.error = null; this.onsuccess = null; this.onerror = null; } }

class Store {
	constructor(def, tx) { this.def = def; this.tx = tx; }
	_req(fn) {
		const r = new Request();
		this.tx._pending++;
		later(() => { r.result = fn(); if (r.onsuccess) r.onsuccess({ target: r }); this.tx._pending--; this.tx._check(); });
		return r;
	}
	_key(value, key) { return this.def.keyPath ? value[this.def.keyPath] : key; }
	get(key) { return this._req(() => clone(this.def.data.get(key))); }
	getAll() { return this._req(() => [...this.def.data.values()].map(clone)); }
	put(value, key) { const k = this._key(value, key); const v = clone(value); return this._req(() => { this.def.data.set(k, v); return k; }); }
	delete(key) { return this._req(() => { this.def.data.delete(key); }); }
	clear() { return this._req(() => { this.def.data.clear(); }); }
	createIndex(name, path) { this.def.indexes[name] = path; }
	index(name) {
		const path = this.def.indexes[name];
		return { getAll: (value) => this._req(() => [...this.def.data.values()].filter((v) => v[path] === value).map(clone)) };
	}
}

class Transaction {
	constructor(db, names) {
		this.db = db; this.names = [].concat(names); this._pending = 0; this.oncomplete = null; this.onerror = null; this.onabort = null; this.error = null;
		// A transaction without requests completes on the next tick, too.
		later(() => this._check());
	}
	objectStore(name) {
		if (!this.names.includes(name)) throw new Error('store not in transaction: ' + name);
		return new Store(this.db.stores[name], this);
	}
	_check() {
		later(() => { if (this._finished || this._pending > 0) return; this._finished = true; if (this.oncomplete) this.oncomplete(); });
	}
}

class Database {
	constructor() { this.stores = {}; }
	createObjectStore(name, opts) {
		this.stores[name] = { keyPath: opts && opts.keyPath, data: new Map(), indexes: {} };
		return new Store(this.stores[name], { _pending: 0, _check() {} });
	}
	transaction(names) { return new Transaction(this, names); }
	/** Test helper: plain contents of a store. */
	dump(name) { return [...this.stores[name].data.entries()]; }
}

function fakeIndexedDB() {
	const dbs = {};
	return {
		dbs,
		open(name) {
			const req = new Request();
			later(() => {
				let db = dbs[name];
				if (!db) {
					db = dbs[name] = new Database();
					req.result = db;
					if (req.onupgradeneeded) req.onupgradeneeded({ target: req });
				}
				req.result = db;
				if (req.onsuccess) req.onsuccess({ target: req });
			});
			return req;
		},
	};
}

module.exports = { fakeIndexedDB };
