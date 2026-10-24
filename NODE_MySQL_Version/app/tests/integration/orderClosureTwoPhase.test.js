'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const orderRepository = require('../../src/repositories/orderRepository');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
const orderExportBenefitSchemeRepository = require('../../src/repositories/orderExportBenefitSchemeRepository');
const clientPortalController = require('../../src/controllers/clientPortalController');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

async function createTestDocument(orderId, typeCode, status) {
  const docType = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
  const result = await db.execute(
    `INSERT INTO documents (order_id, document_type_id, document_reference, status)
     VALUES (:order_id, :dt, :ref, :status)`,
    { order_id: orderId, dt: docType.id, ref: `JEST-CLOSURE-DOC-${Math.random().toString(16).slice(2, 10)}`, status }
  );
  return result.insertId;
}

/**
 * Port of OrderClosureTwoPhaseTest.php. docs/schema.sql Section BU —
 * export process steps 11a-11e: closing an order is now two phases.
 * 'shipped_docs_sent' (documents couriered — client sees it as done,
 * stays unlocked internally) -> 'complete' (government export benefits
 * received or never applicable — the real final lock).
 */
describe('Two-phase order closure (Section BU)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Closure Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-closure-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, locals) => { res.view = view; res.locals = locals; };
    return res;
  }

  function fakeReq(overrides = {}) {
    return { params: {}, body: {}, session: {}, permissions: {}, ...overrides };
  }

  async function unlockThrough(orderId, throughStage) {
    for (let stage = 1; stage <= throughStage; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
  }

  // Every order now gets real 'not_started' compliance-task rows at creation — resolve them so they don't block closure in tests unrelated to that gate.
  async function resolveAllComplianceTasks(orderId, userId) {
    for (const ct of await orderComplianceTaskRepository.forOrder(orderId)) {
      await orderComplianceTaskRepository.setStatus(orderId, ct.task_type_id, 'skipped', 'not applicable to this test order', userId);
    }
  }

  // Every order now gets real 'pending' export-benefit-scheme rows at creation — resolve them so they don't block closure in tests unrelated to that gate.
  async function resolveAllExportBenefitSchemes(orderId, userId) {
    for (const s of await orderExportBenefitSchemeRepository.forOrder(orderId)) {
      await orderExportBenefitSchemeRepository.setStatus(orderId, s.scheme_name, 'not_eligible', 'not applicable to this test order', userId);
    }
  }

  async function closeToShippedDocsSent(orderId, userId) {
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk', vessel_name: 'MSC MAYA', etd: '2026-06-01', eta: '2026-06-20' });
    await orderShippingRepository.recordBl(orderId, 'MSCU1234567', '2026-06-15');
    await unlockThrough(orderId, 8);
    await createTestDocument(orderId, 'BLI', 'approved');
    await createTestDocument(orderId, 'COOPREP', 'sent');
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, permissions: { close_orders: true }, body: { courier_tracking_number: 'DHL-TWO-PHASE-1' } });
    await ordersController.closeOrder(req, fakeRes());
    flash.pull(req);
  }

  // ---------------------------------------------------------------
  // closeOrder() — phase 1
  // ---------------------------------------------------------------

  it('closeOrder reaches shipped_docs_sent without locking the order', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await closeToShippedDocsSent(orderId, userId);

    const order = await orderRepository.find(orderId);
    expect(order.status).toBe('shipped_docs_sent');
    expect(Number(order.is_locked)).toBe(0);
  });

  // ---------------------------------------------------------------
  // Export benefits now claimable once shipped_docs_sent, not only 'complete'
  // ---------------------------------------------------------------

  it('export benefit can be claimed once shipped_docs_sent without any lock override', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordBalanceReceived(orderId, 9000.0, '2026-06-01');
    await closeToShippedDocsSent(orderId, userId);

    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { scheme_name: 'RODTEP', claimed_amount: '9000', claimed_at: '2026-06-01' } });
    await orderFinancialsController.addExportBenefit(req, fakeRes());

    expect((await caExportBenefitRepository.forOrder(orderId)).length).toBe(1);
    expect(flash.pull(req)[0].type).toBe('success');
  });

  // ---------------------------------------------------------------
  // markFullySettled() — phase 2
  // ---------------------------------------------------------------

  it('refuses an order not yet shipped_docs_sent', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId } });

    await ordersController.markFullySettled(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
    expect((await orderRepository.find(orderId)).status).toBe('active');
  });

  it('returns 404 for an unknown order', async () => {
    const userId = await createTestUser();
    const req = fakeReq({ params: { id: '999999999' }, user: { id: userId } });
    const res = fakeRes();

    await ordersController.markFullySettled(req, res);

    expect(res.statusCode).toBe(404);
  });

  it('succeeds with no benefit claims at all', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await closeToShippedDocsSent(orderId, userId);
    await resolveAllComplianceTasks(orderId, userId);
    await resolveAllExportBenefitSchemes(orderId, userId);
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId } });

    await ordersController.markFullySettled(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('success');
    const order = await orderRepository.find(orderId);
    expect(order.status).toBe('complete');
    expect(Number(order.is_locked)).toBe(1);
  });

  it('blocks while a compliance task is still outstanding', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await closeToShippedDocsSent(orderId, userId);
    // Deliberately does NOT resolve compliance tasks — every order now
    // gets real 'not_started' rows at creation, and this is the test
    // that confirms closure is actually blocked on them.
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId } });

    await ordersController.markFullySettled(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
    expect((await orderRepository.find(orderId)).status).toBe('shipped_docs_sent');
  });

  it('blocks while an export-benefit scheme is still pending', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await closeToShippedDocsSent(orderId, userId);
    await resolveAllComplianceTasks(orderId, userId);
    // Deliberately does NOT resolve export-benefit schemes.
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId } });

    await ordersController.markFullySettled(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
    expect((await orderRepository.find(orderId)).status).toBe('shipped_docs_sent');
  });

  it('blocks while a benefit claim is still awaiting receipt', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await closeToShippedDocsSent(orderId, userId);
    await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-001', 5000.0, '2026-06-01', 'INR', null, userId);
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId } });

    await ordersController.markFullySettled(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
    expect((await orderRepository.find(orderId)).status).toBe('shipped_docs_sent');
  });

  it('succeeds once the benefit is received', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await closeToShippedDocsSent(orderId, userId);
    const benefitId = await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-002', 5000.0, '2026-06-01', 'INR', null, userId);
    await caExportBenefitRepository.markReceived(benefitId, 5100.0, '2026-07-01');
    await resolveAllComplianceTasks(orderId, userId);
    await resolveAllExportBenefitSchemes(orderId, userId);
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId } });

    await ordersController.markFullySettled(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('success');
    expect((await orderRepository.find(orderId)).status).toBe('complete');
  });

  it('override requires edit_locked_data permission', async () => {
    const userId = await createTestUser('Export Executive'); // holds close_orders, not edit_locked_data
    const orderId = await createTestOrder(await createTestClient());
    await closeToShippedDocsSent(orderId, userId);
    await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-003', 5000.0, '2026-06-01', 'INR', null, userId);

    const req = fakeReq({
      params: { id: String(orderId) }, user: { id: userId }, permissions: { edit_locked_data: false },
      body: { override_gate: '1', override_reason: 'Trying to force it through.' },
    });
    await ordersController.markFullySettled(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
    expect((await orderRepository.find(orderId)).status).toBe('shipped_docs_sent');
  });

  it('override requires a reason', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await closeToShippedDocsSent(orderId, userId);
    await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-004', 5000.0, '2026-06-01', 'INR', null, userId);

    const req = fakeReq({
      params: { id: String(orderId) }, user: { id: userId }, permissions: { edit_locked_data: true },
      body: { override_gate: '1', override_reason: 'short' },
    });
    await ordersController.markFullySettled(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
    expect((await orderRepository.find(orderId)).status).toBe('shipped_docs_sent');
  });

  it('override succeeds and is audit logged', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await closeToShippedDocsSent(orderId, userId);
    await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-005', 5000.0, '2026-06-01', 'INR', null, userId);

    const req = fakeReq({
      params: { id: String(orderId) }, user: { id: userId }, permissions: { edit_locked_data: true },
      body: { override_gate: '1', override_reason: 'Buyer confirms RoDTEP will never be claimed for this shipment.' },
    });
    await ordersController.markFullySettled(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('success');
    expect((await orderRepository.find(orderId)).status).toBe('complete');

    const log = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'ORDER_FULLY_SETTLED' AND entity_id = :id",
      { id: orderId }
    );
    expect(Number(log.c)).toBe(1);
  });

  // ---------------------------------------------------------------
  // Cancellation request now blocked at shipped_docs_sent too
  // ---------------------------------------------------------------

  it('requestCancellation is refused once shipped_docs_sent', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await closeToShippedDocsSent(orderId, userId);

    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { reason: 'Buyer wants to cancel after shipment, trying anyway.' } });
    await ordersController.requestCancellation(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
  });

  // ---------------------------------------------------------------
  // View data — client-facing status label
  // ---------------------------------------------------------------

  it('client dashboard groups a shipped_docs_sent order under the Complete label', async () => {
    const clientId = await createTestClient('client-dashboard-test@example.test');
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser();
    await closeToShippedDocsSent(orderId, userId);

    const req = fakeReq({ session: { _client_portal_client_id: clientId } });
    const res = fakeRes();
    await clientPortalController.dashboard(req, res);

    // docs/schema.sql Section BU — shipped_docs_sent is staff-only
    // wording; the client-facing dashboard groups it under "Complete"
    // (clientPortalController.groupOrdersByClientFacingStatus), never as
    // its own separate group.
    const completeGroup = res.locals.orderGroups.find((g) => g.label === 'Complete');
    expect(completeGroup).toBeDefined();
    const order = completeGroup.orders.find((o) => Number(o.id) === orderId);
    expect(order).toBeDefined();
    expect(order.status).toBe('shipped_docs_sent');
    expect(res.locals.orderGroups.some((g) => g.label === 'Shipped_docs_sent' || g.label === 'shipped_docs_sent')).toBe(false);
  });
});
