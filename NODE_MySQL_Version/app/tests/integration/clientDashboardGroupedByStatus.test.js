'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientPortalController = require('../../src/controllers/clientPortalController');
const orderStageRepository = require('../../src/repositories/orderStageRepository');
const { createTestClient, createTestOrder, createTestClientLogin } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * A lightweight "group by status" split on the client portal's My
 * Orders list — not a full Kanban board (the client portal stays the
 * simple list it is), just a quick visual split once a client has more
 * than a couple of orders on file. Grouped by the client-FACING label,
 * so shipped_docs_sent and complete share one "Complete" group — the
 * client was never meant to see that internal distinction as two
 * separate buckets.
 */
describe('Client dashboard grouped by status', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: 200, body: null, view: null, locals: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.renderView = (view, locals) => { res.view = view; res.locals = locals; };
    return res;
  }

  async function render(clientId) {
    const req = { session: { _client_portal_client_id: clientId } };
    const res = fakeRes();
    await clientPortalController.dashboard(req, res);
    return res;
  }

  /**
   * createTestOrder() always inserts sequence_no = 1, so a second call
   * for the same client collides on uq_client_sequence — this mirrors
   * clientOrderHistoryVisibility.test.js's own local helper to create a
   * second order for one client with a distinct sequence.
   */
  async function createTestOrderWithSequence(clientId, sequenceNo) {
    const [incoterm, currency, preset] = await Promise.all([
      db.queryOne("SELECT id, code FROM incoterms WHERE code = 'FOB'"),
      db.queryOne("SELECT id, code FROM currencies WHERE code = 'USD'"),
      db.queryOne(
        `SELECT id, preset_name, advance_pct, advance_trigger_text, balance_pct, balance_trigger_option,
                balance_days, balance_trigger_wording, show_bl_instructions
         FROM payment_presets WHERE preset_name = 'Standard — New Buyer'`
      ),
    ]);

    const orderResult = await db.execute(
      `INSERT INTO orders
         (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
          currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status,
          incoterm_code, currency_code, preset_name, advance_pct, advance_trigger_text,
          balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, show_bl_instructions,
          company_legal_name)
       VALUES
         (:ref, :client_id, :seq, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, 'TBC', 'NIL',
          CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active',
          :incoterm_code, :currency_code, :preset_name, :advance_pct, :advance_trigger_text,
          :balance_pct, :balance_trigger_option, :balance_days, :balance_trigger_wording, :show_bl_instructions,
          :company_legal_name)`,
      {
        ref: `JEST-TEST-${Math.random().toString(16).slice(2, 10)}`,
        client_id: clientId,
        seq: sequenceNo,
        inquiry_ref: `JEST-${Math.random().toString(16).slice(2, 8)}`,
        preset_id: preset.id,
        incoterm_id: incoterm.id,
        currency_id: currency.id,
        company_legal_name: 'Jest Test Buyer Ltd',
        incoterm_code: incoterm.code,
        currency_code: currency.code,
        preset_name: preset.preset_name,
        advance_pct: preset.advance_pct,
        advance_trigger_text: preset.advance_trigger_text,
        balance_pct: preset.balance_pct,
        balance_trigger_option: preset.balance_trigger_option,
        balance_days: preset.balance_days,
        balance_trigger_wording: preset.balance_trigger_wording,
        show_bl_instructions: preset.show_bl_instructions,
      }
    );
    const orderId = orderResult.insertId;
    await orderStageRepository.initializeForOrder(orderId);
    return orderId;
  }

  it('orders of different statuses are returned as separate groups', async () => {
    const clientId = await createTestClient();
    await createTestOrder(clientId);
    const lostId = await createTestOrderWithSequence(clientId, 2);
    await db.execute("UPDATE orders SET status = 'lost' WHERE id = :id", { id: lostId });

    const res = await render(clientId);

    const activeGroup = res.locals.orderGroups.find((g) => g.label === 'Active');
    const lostGroup = res.locals.orderGroups.find((g) => g.label === 'Lost');
    expect(activeGroup.orders.length).toBe(1);
    expect(lostGroup.orders.length).toBe(1);
  });

  it('groups are returned in the fixed, most-relevant-first order', async () => {
    const clientId = await createTestClient();
    const cancelledId = await createTestOrder(clientId);
    await db.execute("UPDATE orders SET status = 'cancelled' WHERE id = :id", { id: cancelledId });
    await createTestOrderWithSequence(clientId, 2);

    const res = await render(clientId);
    const labels = res.locals.orderGroups.map((g) => g.label);

    expect(labels.indexOf('Active')).toBeLessThan(labels.indexOf('Cancelled'));
  });

  it('a status with no orders produces no group', async () => {
    const clientId = await createTestClient();
    await createTestOrder(clientId);

    const res = await render(clientId);
    const labels = res.locals.orderGroups.map((g) => g.label);

    expect(labels).not.toContain('Lost');
    expect(labels).not.toContain('Cancelled');
    expect(labels).not.toContain('Disputed');
  });

  it('shipped_docs_sent and true complete share one Complete group, not two', async () => {
    const clientId = await createTestClient();
    const shippedId = await createTestOrder(clientId);
    await db.execute("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id", { id: shippedId });
    const completeId = await createTestOrderWithSequence(clientId, 2);
    await db.execute("UPDATE orders SET status = 'complete' WHERE id = :id", { id: completeId });

    const res = await render(clientId);
    const completeGroups = res.locals.orderGroups.filter((g) => g.label === 'Complete');

    expect(completeGroups.length).toBe(1);
    expect(completeGroups[0].orders.length).toBe(2);
  });

  it('no orders returns an empty group list', async () => {
    const clientId = await createTestClient();

    const res = await render(clientId);

    expect(res.locals.orderGroups).toEqual([]);
  });

  it('the real template renders the grouped headings over real HTTP', async () => {
    const clientEmail = `jest-dashboard-grouped-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
    const clientId = await createTestClient(clientEmail);
    await createTestClientLogin(clientId, 'JestDashboardGrouped123!');
    await createTestOrder(clientId);
    const lostId = await createTestOrderWithSequence(clientId, 2);
    await db.execute("UPDATE orders SET status = 'lost' WHERE id = :id", { id: lostId });

    const agent = request.agent(app);
    const loginPage = await agent.get('/client/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/client/login').type('form').send({ _csrf: csrf, email: clientEmail, password: 'JestDashboardGrouped123!' });
    expect(loginRes.status).toBe(302);

    const res = await agent.get('/client');
    expect(res.status).toBe(200);
    expect(res.text).toContain('Active (1)');
    expect(res.text).toContain('Lost (1)');
  });
});
