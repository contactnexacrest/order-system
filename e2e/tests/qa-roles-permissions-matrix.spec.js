'use strict';

/**
 * Q3 QA matrix (5 Nov 2026): every staff role, and a user holding one single
 * permission for each of the permissions, signs in and opens every staff GET
 * route (real ids from the dev DB). Fails on any server error, any PHP/Node
 * error text in a page, and any page the route's permission gate should refuse
 * but that answered 200; also checks an order of another division is never
 * shown to Export staff. "Gate allows but the page refused" rows (object-level,
 * module or division rules) are written to the report for review, not failed.
 * Report: QA_MATRIX_OUT (JSON). QA_MATRIX_SAMPLE=n limits the users (smoke run).
 * Run against each stack's dev server with BASE_URL, like the rest of this suite.
 */
const fs = require('fs');
const { test, expect } = require('@playwright/test');
const qa = require('../support/qa-matrix');

test.setTimeout(4 * 60 * 60 * 1000);

test('roles and single permissions: every staff GET route answers as its gate says, without errors', async () => {
  const inventory = qa.loadInventory();
  let users = qa.setup(inventory);
  try {
    if (process.env.QA_MATRIX_SAMPLE) users = users.filter((u, i) => i % Math.ceil(users.length / Number(process.env.QA_MATRIX_SAMPLE)) === 0);
    const results = await qa.run(users, inventory);
    const division = await qa.divisionSample(users);
    const bugs = results.filter((r) => r.level === 'BUG').concat(division.filter((r) => r.level === 'BUG'));
    const notes = results.filter((r) => r.level === 'NOTE');
    const out = process.env.QA_MATRIX_OUT;
    if (out) {
      fs.writeFileSync(out, JSON.stringify({
        stack: qa.isNode() ? 'node' : 'php', users: users.length, requests: results.length,
        ok: results.filter((r) => r.level === 'OK').length, bugs, notes, division,
      }, null, 2));
    }
    expect(results.length).toBeGreaterThan(0);
    expect(bugs).toEqual([]);
  } finally {
    qa.cleanup();
  }
});
