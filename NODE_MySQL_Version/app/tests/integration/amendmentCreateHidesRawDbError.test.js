'use strict';

const db = require('../../src/config/db');
const amendmentController = require('../../src/controllers/amendmentController');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * External QA report R-02: "Raw SQL error text shown to users in the red
 * message box (not only in debug mode — the exception message is passed
 * to the flash). — Where: cancel e-mail send, create amendment, send
 * document." Reproduced here for the "create amendment" flow: an
 * impossible date (amendments.effective_from is DATE NULL) makes the
 * INSERT throw a mysql2 error ("Incorrect date value...", sqlState
 * '22007') — amendmentController.create()'s own `catch (e)` sees this
 * BEFORE it could ever reach server.js's global SQLSTATE handler, so that
 * global fix (R-01/R-03/R-04) does not cover this case; only
 * flash.setErrorFromException() does.
 */
describe('Amendment create hides raw DB error text (External QA report R-02)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Managing Director') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest R-02 Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-r02-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('shows a friendly message, not raw SQLSTATE text, for an impossible effective_from date', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    const req = {
      params: { id: String(orderId) },
      body: {
        reason: 'Jest R-02 regression test',
        requested_by: 'importer',
        effective_from: '2026-02-30', // 30-Feb does not exist
      },
      user: { id: userId },
      session: {},
    };
    const res = fakeRes();

    await amendmentController.create(req, res);

    const [entry] = flash.pull(req);
    expect(entry.type).toBe('error');
    expect(entry.message).not.toContain('SQLSTATE');
    expect(entry.message).not.toContain('Incorrect date value');
    expect(entry.message).toBe('Something went wrong. Please try again.');
  });
});
