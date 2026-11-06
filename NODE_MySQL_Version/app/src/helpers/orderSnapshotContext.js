'use strict';

const { AsyncLocalStorage } = require('async_hooks');

/**
 * Owner decision D-262 (6 Nov 2026): while an order's documents are made
 * (orderSnapshotService.within), its frozen company values and clauses are
 * read instead of the live ones. AsyncLocalStorage keeps the overlay to that
 * one async chain, so concurrent requests never see each other's values
 * (the PHP twin uses a static per request).
 */
const storage = new AsyncLocalStorage();

function current() {
  return storage.getStore() || null;
}

/** Runs fn with {company, terms} in place of the live values. */
function run(overlay, fn) {
  return storage.run(overlay, fn);
}

module.exports = { current, run };
