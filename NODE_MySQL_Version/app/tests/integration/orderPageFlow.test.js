'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const userRepository = require('../../src/repositories/userRepository');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of OrderPageFlowTest.php. Owner requests (2026-10-26) for the order
 * page flow: a remittance is recorded together with the day's exchange
 * rate; each Compliance Checklist row saves on its own (JSON, no page
 * reload) and "Mark All N/A" works per checklist part; the supplier's
 * balance can still be paid long after Stage 5 passed; the document section
 * flags anything waiting on someone and every Generate button says when its
 * document already exists.
 */
describe('Order page flow (owner requests 2026-10-26)', () => {
  let agent;
  let adminId;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    adminId = (await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  function fakeReq(params, body, headers = {}) {
    const permissions = new Proxy({}, { get: () => true });
    return { params, body, query: {}, permissions, session: {}, user: { id: adminId, name: 'Jest Admin' }, get: (h) => headers[h] };
  }
  function fakeRes() {
    const res = { redirectedTo: null, statusCode: 200, body: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.json = (body) => { res.body = body; };
    return res;
  }
  const lastFlash = (req) => {
    const messages = flash.pull(req);
    return messages.length ? messages[messages.length - 1].message : '';
  };

  it('records an advance remittance together with the day\'s exchange rate', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);

    let req = fakeReq({ id: String(orderId) }, { advance_amount: '1800', advance_received_at: '2026-10-26' });
    await ordersController.recordAdvancePayment(req, fakeRes());
    expect(lastFlash(req)).toContain("today's exchange rate");
    expect((await orderPaymentStatusRepository.find(orderId)).advance_amount).toBeNull();

    req = fakeReq({ id: String(orderId) }, { advance_amount: '1800', advance_received_at: '2026-10-26', advance_exchange_rate: '83.25' });
    await ordersController.recordAdvancePayment(req, fakeRes());
    const payment = await orderPaymentStatusRepository.find(orderId);
    expect(Number(payment.advance_amount)).toBe(1800);
    expect(Number(payment.advance_exchange_rate)).toBe(83.25);
    expect(Number(payment.advance_exchange_rate_set_by)).toBe(adminId);
    expect(lastFlash(req)).toContain("Received USD 1,800.00 at today's exchange rate of ₹83.2500 (= ₹149,850.00).");
  });

  it('saves each compliance row on its own as JSON', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderComplianceTaskRepository.initializeForOrder(orderId);
    const tasks = await orderComplianceTaskRepository.forOrder(orderId);
    const ajax = { 'X-Requested-With': 'XMLHttpRequest' };

    let req = fakeReq({ id: String(orderId) }, { task_type_id: String(tasks[0].task_type_id), status: 'skipped', skip_reason: '' }, ajax);
    let res = fakeRes();
    await ordersController.updateComplianceTask(req, res);
    expect(res.statusCode).toBe(422);
    expect(res.body.ok).toBe(false);
    expect(res.body.message).toContain('reason is required');

    req = fakeReq({ id: String(orderId) }, { task_type_id: String(tasks[0].task_type_id), status: 'approved' }, ajax);
    res = fakeRes();
    await ordersController.updateComplianceTask(req, res);
    expect(res.body.ok).toBe(true);
    expect(res.body.outstanding).toBe(tasks.length - 1);
    expect(req.session._flash).toBeUndefined();
  });

  it('marks only its own checklist part not applicable', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderComplianceTaskRepository.initializeForOrder(orderId);

    const req = fakeReq({ id: String(orderId) }, { skip_reason: 'Repeat order — checklist does not apply', phase: 'order' });
    await ordersController.markAllComplianceTasksNotApplicable(req, fakeRes());

    for (const task of await orderComplianceTaskRepository.forOrder(orderId)) {
      expect([task.task_type_name, task.status]).toEqual([task.task_type_name, task.phase === 'order' ? 'skipped' : 'not_started']);
    }
  });

  it('still lets the supplier balance be paid after Stage 5 has passed', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierId = await supplierRepository.create({ supplier_legal_name: `Flow Test Quarry ${Math.random().toString(16).slice(2, 8)}`, types: ['Trader'] });
    await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-FLOW-1', { balance_amount_inr: '50000' });
    for (let stage = 1; stage <= 6; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }

    const req = fakeReq({ id: String(orderId) }, { paid_date: new Date().toISOString().slice(0, 10), amount_paid_inr: '50000' });
    await ordersController.markSupplierBalancePaid(req, fakeRes());

    const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect([supplierPo.balance_paid_at === null, lastFlash(req)]).toEqual([false, expect.any(String)]);
  });

  it('flags pending document actions and says when a document already exists', async () => {
    const orderId = await createTestOrder(await createTestClient());
    let { text } = await agent.get(`/orders/${orderId}`);
    expect(text).toContain('id="document-review-approval">');
    expect(text).not.toContain('doc-already-note');

    await createTestDocument(orderId, 'QT', 'draft');
    ({ text } = await agent.get(`/orders/${orderId}`));
    expect(text).toContain('id="document-review-approval" open');
    expect(text).toContain('1 needs action');
    expect(text).toContain('generated, no reviewer assigned yet');
    expect(text).toContain('Already generated:');
    expect(text).toContain('before generating a new one');
  });
});
