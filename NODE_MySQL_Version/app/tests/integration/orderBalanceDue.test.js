'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderBalanceDueTest.php + OrderStep6bStatusTest.php. Owner report
 * (2026-10-27, blocker): the Order Confirmation printed the advance actually
 * received under "40% Advance" while the balance was still FOB × 60%. The
 * balance due is now FOB − advance actually received, and a mistyped
 * advance is caught when it is recorded. Also: Step 6b only goes green once
 * the OC is issued.
 */
describe('Balance due after the actual advance (owner report 2026-10-27)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Accounts Executive') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest BalanceDue User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-baldue-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createTestDocument(orderId, typeCode, status) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-BALDUE-${Math.random().toString(16).slice(2, 10)}`, status }
    );
    return result.insertId;
  }

  async function setup() {
    const orderId = await createTestOrder(await createTestClient());
    await orderProductRepository.add(orderId, 1, 'Absolute black granite', null, null, '50', false, 'PCS', '150.00');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    return { orderId, userId: await createTestUser() };
  }

  async function recordAdvance(orderId, userId, body) {
    const req = { user: { id: userId }, params: { id: String(orderId) }, session: {}, body: { advance_received_at: '2026-10-27', advance_exchange_rate: '83.5', advance_bank_reference: 'UTR-TEST-0001', ...body } };
    await ordersController.recordAdvancePayment(req, { redirect: () => {} });
    const messages = flash.pull(req);
    return messages[messages.length - 1] || { type: '', message: '' };
  }

  it('refuses an advance above the order value', async () => {
    const { orderId, userId } = await setup();
    const result = await recordAdvance(orderId, userId, { advance_amount: '7600', confirm_amount_differs: '1' });
    expect(result.type).toBe('error');
    expect(result.message).toContain('more than the whole order value');
    expect((await orderPaymentStatusRepository.find(orderId)).advance_amount).toBeNull();
  });

  it('asks to confirm an advance that differs from the advance due', async () => {
    const { orderId, userId } = await setup();
    const refused = await recordAdvance(orderId, userId, { advance_amount: '7300' });
    expect(refused.type).toBe('error');
    expect(refused.message).toContain('The advance due on this order is USD 3,000.00 (40% of USD 7,500.00)');
    expect((await recordAdvance(orderId, userId, { advance_amount: '2985' })).type).toBe('success');
    expect((await recordAdvance(orderId, userId, { advance_amount: '3500', confirm_amount_differs: '1' })).type).toBe('success');
    expect(parseFloat((await orderPaymentStatusRepository.find(orderId)).advance_amount)).toBeCloseTo(3500);
  });

  it('balance due is the order value minus the advance actually received', async () => {
    const { orderId, userId } = await setup();
    await recordAdvance(orderId, userId, { advance_amount: '3500', confirm_amount_differs: '1' });
    await createTestDocument(orderId, 'PI', 'approved');
    await ordersController.clearAdvancePayment(
      { user: { id: userId }, params: { id: String(orderId) }, session: {}, body: { advance_cleared_at: '2026-10-27' } },
      { redirect: () => {} }
    );
    expect(parseFloat((await orderPaymentStatusRepository.find(orderId)).balance_amount)).toBeCloseTo(4000);

    const context = await documentDataAssembler.assemble(orderId, 'OC');
    expect(context.financial.total_value).toBe('7,500.00');
    expect(context.financial.advance_amount).toBe('3,000.00');
    expect(context.payment_status.advance_amount).toBe('3,500.00');
    expect(context.payment_status.balance_amount).toBe('4,000.00');
  });

  it('corrects a stale stored balance on the documents', async () => {
    const { orderId } = await setup();
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 7300, '2026-10-27');
    await orderPaymentStatusRepository.setBalanceAmount(orderId, 4500, null);
    expect((await documentDataAssembler.assemble(orderId, 'OC')).payment_status.balance_amount).toBe('200.00');
  });

  it('Step 6b waits for the OC to be issued', async () => {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });

    const orderId = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 3; stage++) await stageGateService.passAndUnlockNext(orderId, stage, null);
    await db.execute("UPDATE orders SET buyer_action_token_hash = 'x' WHERE id = :id", { id: orderId });
    const docId = await createTestDocument(orderId, 'OC', 'in_review');
    const icon = (html) => (html.match(/id="step-6b-buyer-action-link"[^>]*>\s*<summary><span class="step-card-icon (\w+)"/) || [])[1];

    expect(icon((await agent.get(`/orders/${orderId}`)).text)).not.toBe('done');
    await db.execute("UPDATE documents SET status = 'approved' WHERE id = :id", { id: docId });
    expect(icon((await agent.get(`/orders/${orderId}`)).text)).toBe('done');
  });
});
