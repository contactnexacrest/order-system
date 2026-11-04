'use strict';

/**
 * Owner decision D-245 P5 (performance): while a report works through many orders, the
 * settings and lists every order needs (incentive settings, pricing settings, …) are read
 * once instead of once per order. Scoped with AsyncLocalStorage, so only the code running
 * inside within() sees the cache — other requests always read fresh values. Outside a
 * batch, memo() simply calls through. Twin of PHP App\Helpers\BatchCache.
 */
const { AsyncLocalStorage } = require('async_hooks');

const storage = new AsyncLocalStorage();

/** Runs fn with a fresh cache; nested calls reuse the outer one. */
function within(fn) {
  if (storage.getStore()) return fn();
  return storage.run(new Map(), fn);
}

/** The cached result of loader() for key inside a batch; a plain call outside one. */
function memo(key, loader) {
  const store = storage.getStore();
  if (!store) return loader();
  if (!store.has(key)) store.set(key, loader());
  return store.get(key);
}

/**
 * Like memo(), for read-only lookups that return a row or a list: each caller gets its own
 * shallow copy, so one caller changing the object cannot affect another.
 */
async function memoRead(key, loader) {
  const store = storage.getStore();
  if (!store) return loader();
  const v = await memo(key, loader);
  if (Array.isArray(v)) return v.map((x) => (x && typeof x === 'object' ? { ...x } : x));
  if (v && typeof v === 'object') return { ...v };
  return v;
}

module.exports = { within, memo, memoRead };
