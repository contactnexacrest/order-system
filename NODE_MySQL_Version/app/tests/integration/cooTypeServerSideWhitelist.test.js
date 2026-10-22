'use strict';

const db = require('../../src/config/db');
const clientsController = require('../../src/controllers/clientsController');
const lookupRepository = require('../../src/repositories/lookupRepository');

/**
 * External QA report R-13: "COO type is not validated on the server: any
 * text (e.g. 'TBC') is stored although the dropdown offers only GSP Form
 * A / Non-Preferential / To Be Confirmed; such clients then lose the
 * value when duplicated." lookupRepository.sanitizeOption() now rejects
 * anything that isn't an active dropdown_options('coo_type') value (or
 * the literal "To Be Confirmed" sentinel), falling back exactly the way
 * an empty submission already did.
 */
describe('coo_type server-side whitelist (External QA report R-13)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest R-13 Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-r13-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = {};
    res.redirect = () => {};
    res.renderView = () => {};
    return res;
  }

  it('sanitizeOption accepts an active dropdown value', async () => {
    expect(await lookupRepository.sanitizeOption('coo_type', 'GSP Form A (Preferential)')).toBe('GSP Form A (Preferential)');
    expect(await lookupRepository.sanitizeOption('coo_type', 'Non-Preferential')).toBe('Non-Preferential');
  });

  it('sanitizeOption accepts the "To Be Confirmed" sentinel and blank', async () => {
    expect(await lookupRepository.sanitizeOption('coo_type', 'To Be Confirmed')).toBe('To Be Confirmed');
    expect(await lookupRepository.sanitizeOption('coo_type', '')).toBe('');
  });

  it('sanitizeOption rejects an arbitrary string like "TBC"', async () => {
    expect(await lookupRepository.sanitizeOption('coo_type', 'TBC')).toBe('');
    expect(await lookupRepository.sanitizeOption('coo_type', 'a,b')).toBe('');
  });

  it('client create stores "TBC" as the To Be Confirmed fallback, not the raw text', async () => {
    const userId = await createTestUser();
    const req = {
      user: { id: userId },
      body: { company_legal_name: 'R-13 Test Buyer Ltd', billing_address: '1 Test Street', coo_type: 'TBC' },
      params: {},
      session: {},
    };

    await clientsController.store(req, fakeRes());

    const client = await db.queryOne(
      "SELECT coo_type FROM clients WHERE company_legal_name = 'R-13 Test Buyer Ltd' ORDER BY id DESC LIMIT 1"
    );
    expect(client).not.toBeNull();
    expect(client.coo_type).toBe('To Be Confirmed');
  });
});
