'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { createTestClient } = require('../support/fixtures');

/**
 * Owner, 14 Nov 2026 (D-375): "Create order" from a client's page opens the New Order form with only that
 * client in the Client drop-down; opened without a client it offers every client. PHP twin:
 * tests/Integration/OrderCreateFromClientTest.php.
 */
describe('D-375: New Order from a client offers only that client', () => {
  const PASSWORD = 'JestOnlyClient!1';

  afterAll(async () => {
    await db.pool.end();
  });

  test('from a client only that client; without one, every client', async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-onlyclient-${crypto.randomBytes(4).toString('hex')}@email.com`;
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });

    const aId = await createTestClient();
    const bId = await createTestClient();
    const num = async (id) => (await db.queryOne('SELECT client_unique_number AS n FROM clients WHERE id = :id', { id })).n;
    const a = await num(aId);
    const b = await num(bId);

    let html = (await agent.get(`/orders/create?client_id=${aId}`)).text;
    expect(html).toContain(a);
    expect(html).not.toContain(b);
    expect(html).toContain('opened from the client&#39;s page'.replace('&#39;', "'"));

    html = (await agent.get('/orders/create')).text;
    expect(html).toContain(a);
    expect(html).toContain(b);
    expect(html).not.toContain("opened from the client's page");
  });
});
