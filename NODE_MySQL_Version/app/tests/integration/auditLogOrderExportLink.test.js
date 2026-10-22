'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * External QA report M-03: "One-click export of an order's audit log
 * (privileged only)." The export itself reuses the page's existing
 * view_audit_log permission gate, so what this test actually guards is
 * discoverability — the "Export CSV" link must render right on the
 * order's own Audit Log page — plus that the ?format=csv route behind it
 * actually works end to end. Port of PHP's AuditLogOrderExportLinkTest.
 */
describe('Order audit log export (External QA report M-03)', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('the order audit log page offers an Export CSV link', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}/audit-log`);

    expect(res.text).toContain(`/orders/${orderId}/audit-log?format=csv`);
    expect(res.text).toContain('Export CSV');
  });

  it('the export link actually returns a CSV', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}/audit-log?format=csv`);

    expect(res.status).toBe(200);
    expect(res.headers['content-type']).toContain('text/csv');
    expect(res.text).toContain('When,User,Action,Entity,Field,Old,New,Reason');
  });
});
