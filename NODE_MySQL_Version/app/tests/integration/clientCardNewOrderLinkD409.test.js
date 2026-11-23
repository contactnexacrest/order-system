'use strict';

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { createTestClient } = require('../support/fixtures');

/**
 * Port of ClientCardNewOrderLinkD409Test.php — owner decision D-409 (23 Nov 2026): every client card on
 * the Clients page has a "+ New Order" link (same link as on the client page), shown only to people
 * who may create orders.
 */
describe('D-409 "+ New Order" on the client card', () => {
  const PASSWORD = 'JestD409!1';

  afterAll(async () => {
    await db.pool.end();
  });

  async function signInAs(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-d409-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return agent;
  }

  test('the card carries a New Order link for that client', async () => {
    const clientId = await createTestClient();
    const agent = await signInAs('Export Executive');
    const html = (await agent.get('/clients')).text;
    expect(html).toContain(`<a class="entity-card-action" href="/orders/create?client_id=${clientId}" data-testid="client-card-new-order">+ New Order</a>`);
  });

  test('a read-only user sees no New Order link', async () => {
    await createTestClient();
    const agent = await signInAs('Viewer / Auditor');
    const html = (await agent.get('/clients')).text;
    expect(html).toContain('Clients');
    expect(html).not.toContain('client-card-new-order');
  });
});
