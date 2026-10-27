'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const orderExportBenefitSchemeRepository = require('../../src/repositories/orderExportBenefitSchemeRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const userRepository = require('../../src/repositories/userRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderStep12SubStepsTest.php. Owner decision (2026-10-26): Step 12
 * is four guided sub-steps — 12a costs (marked complete), 12b scheme
 * eligibility, 12c benefits claimed and received in one table with one
 * Save, 12d review & complete.
 */
describe('Step 12 sub-steps (12a–12d)', () => {
  let adminId;
  let staff;

  beforeAll(async () => {
    adminId = (await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id;
    staff = request.agent(app);
    const login = await staff.get('/login');
    const csrf = login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await staff.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  const fakeReq = (orderId, body = {}) => ({ params: { id: String(orderId) }, body, query: {}, session: {}, user: { id: adminId, name: 'Jest Admin' } });
  const fakeRes = () => ({ redirect() {}, status() { return this; }, send() {} });
  const lastFlash = (req) => {
    const messages = flash.pull(req);
    return messages.length ? messages[messages.length - 1] : { type: '', message: '' };
  };
  async function resolveSchemes(orderId, eligibleScheme) {
    for (const row of await orderExportBenefitSchemeRepository.forOrder(orderId)) {
      const eligible = row.scheme_name === eligibleScheme;
      await orderExportBenefitSchemeRepository.setStatus(orderId, row.scheme_name, eligible ? 'eligible' : 'not_eligible', eligible ? null : 'Not applicable to this order', adminId);
    }
  }

  it('completes Step 12 only after costs and eligibility', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const page = async () => (await staff.get(`/orders/${orderId}`)).text;
    const html = await page();
    for (const id of ['financials-12a', 'financials-12b', 'financials-12c', 'step-13g-benefits']) {
      expect(html).toContain(`id="${id}"`);
    }

    let req = fakeReq(orderId);
    await orderFinancialsController.completeFinancials(req, fakeRes());
    const refused = lastFlash(req);
    expect(refused.type).toBe('error');
    expect(refused.message).toContain('12a');
    expect(refused.message).toContain('12b');

    await orderFinancialsController.confirmCosts(fakeReq(orderId), fakeRes());
    expect((await orderRepository.find(orderId)).financials_costs_confirmed_at).not.toBeNull();
    await resolveSchemes(orderId, 'RODTEP');

    req = fakeReq(orderId);
    await orderFinancialsController.completeFinancials(req, fakeRes());
    expect(lastFlash(req).type).toBe('success');
    expect((await orderRepository.find(orderId)).financials_completed_at).not.toBeNull();
    expect(await page()).toContain('&#10003; Step 12 completed on');

    await orderFinancialsController.completeFinancials(fakeReq(orderId, { action: 'reopen' }), fakeRes());
    await orderFinancialsController.confirmCosts(fakeReq(orderId, { action: 'reopen' }), fakeRes());
    const order = await orderRepository.find(orderId);
    expect(order.financials_completed_at).toBeNull();
    expect(order.financials_costs_confirmed_at).toBeNull();
  });

  it('records claims and the money received with one Save', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await resolveSchemes(orderId, 'RODTEP');
    const waitingId = await caExportBenefitRepository.record(orderId, 'Duty Drawback', 'SB-1', 9800, '2026-10-01', 'INR', null, adminId);
    const body = { benefits: {
      0: { benefit_id: String(waitingId), received_amount: '9800', received_at: '2026-10-20' },
      1: { scheme_name: 'RODTEP', reference_number: 'SB-2', claimed_amount: '18450', claimed_at: '2026-10-05', received_amount: '', received_at: '2026-10-26' },
    } };

    let req = fakeReq(orderId, body);
    await orderFinancialsController.saveExportBenefits(req, fakeRes());
    expect(lastFlash(req).message).toContain('documents are sent');
    expect(await caExportBenefitRepository.forOrder(orderId)).toHaveLength(1);

    await db.execute("UPDATE orders SET status = 'complete' WHERE id = :id", { id: orderId });
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await db.execute('UPDATE order_payment_status SET balance_remittance_received_at = NOW() WHERE order_id = :id', { id: orderId });

    body.benefits[1].claimed_at = 'not-a-date';
    req = fakeReq(orderId, body);
    await orderFinancialsController.saveExportBenefits(req, fakeRes());
    expect(lastFlash(req).message).toContain('Nothing was saved');
    expect((await caExportBenefitRepository.find(waitingId)).received_amount).toBeNull();

    body.benefits[1].claimed_at = '2026-10-05';
    req = fakeReq(orderId, body);
    await orderFinancialsController.saveExportBenefits(req, fakeRes());
    expect(lastFlash(req).message).toBe('Benefits saved — 1 claim(s) recorded, 1 receipt(s) recorded.');
    const claims = Object.fromEntries((await caExportBenefitRepository.forOrder(orderId)).map((c) => [c.scheme_name, c]));
    expect(Number(claims['Duty Drawback'].received_amount)).toBe(9800);
    expect(Number(claims.RODTEP.claimed_amount)).toBe(18450);
    expect(claims.RODTEP.received_amount).toBeNull();
  });
});
