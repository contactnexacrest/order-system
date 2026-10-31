'use strict';

const { AsyncLocalStorage } = require('async_hooks');

// Owner decision D-214: what PHP reads from the one request it serves
// ($_SESSION — the department a person is working as), Node keeps per
// request here, so code with no `req` (the audit log, permission loads)
// still knows it. sessionAuth.required() opens it for every staff request.

const storage = new AsyncLocalStorage();

function run(store, fn) {
  return storage.run(store, fn);
}

/** @returns {object|null} { userId, effectiveRoleId, previewing, actingAs } of the signed-in staff request, if any */
function get() {
  return storage.getStore() || null;
}

module.exports = { run, get };
