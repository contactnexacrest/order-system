'use strict';

const db = require('../../src/config/db');
const clientsController = require('../../src/controllers/clientsController');
const clientPortalController = require('../../src/controllers/clientPortalController');
const clientRepository = require('../../src/repositories/clientRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const orderStageRepository = require('../../src/repositories/orderStageRepository');

async function createTestUser(roleName = 'Admin') {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-ohv-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

/**
 * Port of ClientOrderHistoryVisibilityTest.php. 90-item backlog Phase 6
 * Item B — a two-level visibility config for whether a client sees their
 * own full order history (including completed/lost orders) on the "My
 * Orders" portal dashboard, vs. active/disputed orders only: a global
 * default (company_settings.client_portal_show_order_history) with a
 * per-client override (clients.order_history_visibility_override, null
 * = inherit).
 */
describe('Client order history visibility (90-item backlog Phase 6 Item B)', () => {
  let userId;

  beforeAll(async () => {
    userId = await createTestUser('Admin');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: null, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, locals) => { res.view = view; res.locals = locals; };
    return res;
  }

  function fakeReq(body = {}, params = {}) {
    return { session: {}, user: { id: userId }, body, params };
  }

  async function createTestClient() {
    const result = await db.execute(
      `INSERT INTO clients (client_unique_number, company_legal_name, billing_address, created_by)
       VALUES (:num, 'Jest Test Buyer Ltd', '1 Test Street, Test City', NULL)`,
      { num: `TEST-${Math.random().toString(16).slice(2, 10)}` }
    );
    return result.insertId;
  }

  async function createTestOrder(clientId, sequenceNo = 1) {
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
          balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, show_bl_instructions)
       VALUES
         (:ref, :client_id, :seq, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, 'TBC', 'NIL',
          CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active',
          :incoterm_code, :currency_code, :preset_name, :advance_pct, :advance_trigger_text,
          :balance_pct, :balance_trigger_option, :balance_days, :balance_trigger_wording, :show_bl_instructions)`,
      {
        ref: `JEST-TEST-${Math.random().toString(16).slice(2, 10)}`,
        client_id: clientId,
        seq: sequenceNo,
        inquiry_ref: `JEST-${Math.random().toString(16).slice(2, 8)}`,
        preset_id: preset.id,
        incoterm_id: incoterm.id,
        currency_id: currency.id,
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

  it('override null follows the global default', async () => {
    const clientId = await createTestClient();

    await companySettingsRepository.set('client_portal_show_order_history', '1');
    expect(await clientRepository.orderHistoryVisible(await clientRepository.find(clientId))).toBe(true);

    await companySettingsRepository.set('client_portal_show_order_history', '0');
    expect(await clientRepository.orderHistoryVisible(await clientRepository.find(clientId))).toBe(false);
  });

  it('per-client override wins over the global default', async () => {
    const clientId = await createTestClient();

    await companySettingsRepository.set('client_portal_show_order_history', '0');
    await clientRepository.setOrderHistoryVisibilityOverride(clientId, true);
    expect(await clientRepository.orderHistoryVisible(await clientRepository.find(clientId))).toBe(true);

    await companySettingsRepository.set('client_portal_show_order_history', '1');
    await clientRepository.setOrderHistoryVisibilityOverride(clientId, false);
    expect(await clientRepository.orderHistoryVisible(await clientRepository.find(clientId))).toBe(false);
  });

  it('forClient with activeOnly hides completed and lost orders', async () => {
    const clientId = await createTestClient();
    const activeId = await createTestOrder(clientId, 1);
    const disputedId = await createTestOrder(clientId, 2);
    const completeId = await createTestOrder(clientId, 3);
    const lostId = await createTestOrder(clientId, 4);
    await db.execute("UPDATE orders SET status = 'disputed' WHERE id = :id", { id: disputedId });
    await db.execute("UPDATE orders SET status = 'complete' WHERE id = :id", { id: completeId });
    await db.execute("UPDATE orders SET status = 'lost' WHERE id = :id", { id: lostId });

    const full = await orderRepository.forClient(clientId);
    expect(full.length).toBe(4);

    const activeOnly = await orderRepository.forClient(clientId, true);
    const ids = activeOnly.map((o) => o.id);
    expect(activeOnly.length).toBe(2);
    expect(ids).toContain(activeId);
    expect(ids).toContain(disputedId);
    expect(ids).not.toContain(completeId);
    expect(ids).not.toContain(lostId);
  });

  it('portal dashboard hides history when not visible', async () => {
    const clientId = await createTestClient();
    await createTestOrder(clientId, 1);
    const completeId = await createTestOrder(clientId, 2);
    await db.execute("UPDATE orders SET status = 'complete' WHERE id = :id", { id: completeId });
    await clientRepository.setOrderHistoryVisibilityOverride(clientId, false);

    const req = { session: { _client_portal_client_id: clientId } };
    const res = fakeRes();
    await clientPortalController.dashboard(req, res);

    expect(res.locals.historyHidden).toBe(true);
    expect(res.locals.orders.length).toBe(1);
  });

  it('portal dashboard shows full history when visible', async () => {
    const clientId = await createTestClient();
    await createTestOrder(clientId, 1);
    const completeId = await createTestOrder(clientId, 2);
    await db.execute("UPDATE orders SET status = 'complete' WHERE id = :id", { id: completeId });
    await clientRepository.setOrderHistoryVisibilityOverride(clientId, true);

    const req = { session: { _client_portal_client_id: clientId } };
    const res = fakeRes();
    await clientPortalController.dashboard(req, res);

    expect(res.locals.historyHidden).toBe(false);
    expect(res.locals.orders.length).toBe(2);
  });

  it('setOrderHistoryVisibilityOverride 404s for a missing client', async () => {
    const res = fakeRes();
    await clientsController.setOrderHistoryVisibilityOverride(fakeReq({ order_history_visibility_override: '1' }, { id: '999999999' }), res);

    expect(res.statusCode).toBe(404);
  });

  it('setOrderHistoryVisibilityOverride writes the value and an audit log entry', async () => {
    const clientId = await createTestClient();
    const res = fakeRes();
    await clientsController.setOrderHistoryVisibilityOverride(fakeReq({ order_history_visibility_override: '0' }, { id: String(clientId) }), res);

    const client = await clientRepository.find(clientId);
    expect(Number(client.order_history_visibility_override)).toBe(0);

    const log = await db.queryOne(
      "SELECT * FROM audit_log WHERE action_type = 'CLIENT_ORDER_HISTORY_VISIBILITY_CHANGED' AND entity_type = 'clients' AND entity_id = :id",
      { id: clientId }
    );
    expect(log).toBeTruthy();
  });

  it('setOrderHistoryVisibilityOverride clears back to inherit on an empty post', async () => {
    const clientId = await createTestClient();
    await clientRepository.setOrderHistoryVisibilityOverride(clientId, true);

    const res = fakeRes();
    await clientsController.setOrderHistoryVisibilityOverride(fakeReq({ order_history_visibility_override: '' }, { id: String(clientId) }), res);

    const client = await clientRepository.find(clientId);
    expect(client.order_history_visibility_override).toBeNull();
  });
});
