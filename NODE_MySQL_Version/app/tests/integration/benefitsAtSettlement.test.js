'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const orderRepository = require('../../src/repositories/orderRepository');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
const orderExportBenefitSchemeRepository = require('../../src/repositories/orderExportBenefitSchemeRepository');
const benefitSettlementService = require('../../src/services/benefitSettlementService');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of BenefitsAtSettlementTest.php. Owner decision (2026-10-27, Option
 * A): government benefits are recorded at Step 13g, just before 13h Mark
 * Fully Settled — every eligible scheme must be received, or skipped with a
 * reason, before the order settles.
 */
describe('Benefits at settlement — Step 13g (owner decision 2026-10-27)', () => {
  let userId;

  beforeAll(async () => {
    userId = (await db.queryOne('SELECT id FROM users WHERE email = :email', { email: TEST_ADMIN_EMAIL })).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  const res = () => ({ redirect: () => {}, status() { return this; }, send: () => {} });

  async function closedOrder() {
    const orderId = await createTestOrder(await createTestClient());
    for (const ct of await orderComplianceTaskRepository.forOrder(orderId)) {
      await orderComplianceTaskRepository.setStatus(orderId, ct.task_type_id, 'skipped', 'not applicable to this test order', userId);
    }
    for (const s of await orderExportBenefitSchemeRepository.forOrder(orderId)) {
      const eligible = s.scheme_name === 'RODTEP';
      await orderExportBenefitSchemeRepository.setStatus(orderId, s.scheme_name, eligible ? 'eligible' : 'not_eligible', eligible ? null : 'not applicable to this test order', userId);
    }
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk', vessel_name: 'MSC MAYA', etd: '2026-06-01', eta: '2026-06-20' });
    await orderShippingRepository.recordBl(orderId, 'MSCU1234567', '2026-06-15');
    for (let stage = 1; stage <= 8; stage++) await stageGateService.passAndUnlockNext(orderId, stage, null);
    await createTestDocument(orderId, 'BLI', 'approved');
    await createTestDocument(orderId, 'COOPREP', 'sent');
    const req = { params: { id: String(orderId) }, user: { id: userId }, permissions: { close_orders: true }, body: { courier_tracking_number: 'DHL-13G-1' }, session: {} };
    await ordersController.closeOrder(req, res());
    expect((await orderRepository.find(orderId)).status).toBe('shipped_docs_sent');
    return orderId;
  }

  async function call(fn, orderId, body) {
    const req = { params: { id: String(orderId) }, user: { id: userId, role_id: null }, permissions: { close_orders: true }, body, session: {} };
    await fn(req, res());
    const messages = flash.pull(req);
    return messages[messages.length - 1] || { type: '', message: '' };
  }

  it('blocks settling an unclaimed eligible scheme until it is skipped', async () => {
    const orderId = await closedOrder();
    expect((await benefitSettlementService.state(orderId)).unclaimed).toEqual(['RODTEP']);
    const refused = await call(ordersController.markFullySettled, orderId, {});
    expect(refused.type).toBe('error');
    expect(refused.message).toContain('has not been claimed yet (RODTEP)');

    expect((await call(orderFinancialsController.skipExportBenefitClaim, orderId, { scheme_name: 'RODTEP', reason: '' })).type).toBe('error');
    expect((await call(orderFinancialsController.skipExportBenefitClaim, orderId, { scheme_name: 'RODTEP', reason: 'Below the minimum claim amount' })).type).toBe('success');
    expect((await benefitSettlementService.state(orderId)).done).toBe(true);
    expect((await call(orderFinancialsController.skipExportBenefitClaim, orderId, { scheme_name: 'RODTEP', action: 'undo' })).type).toBe('success');
    expect((await benefitSettlementService.state(orderId)).done).toBe(false);

    await call(orderFinancialsController.skipExportBenefitClaim, orderId, { scheme_name: '', reason: 'Company decided not to file any claims' });
    expect((await call(ordersController.markFullySettled, orderId, {})).type).toBe('success');
    expect((await orderRepository.find(orderId)).status).toBe('complete');
  });

  it('settles a received claim and blocks a waiting one', async () => {
    const orderId = await closedOrder();
    const claimId = await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-1', 9000, '2026-06-20', 'INR', null, userId);
    expect((await benefitSettlementService.state(orderId)).waiting).toEqual(['RODTEP']);
    expect((await call(ordersController.markFullySettled, orderId, {})).message).toContain('still awaiting receipt (RODTEP)');
    await caExportBenefitRepository.markReceived(claimId, 9000, '2026-07-01');
    expect((await call(ordersController.markFullySettled, orderId, {})).type).toBe('success');
  });

  it('shows 13g before 13h on the order page', async () => {
    const orderId = await closedOrder();
    const agent = request.agent(app);
    const login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    const html = (await agent.get(`/orders/${orderId}`)).text;
    expect(html).toContain('Step 13g &middot; Government Benefits');
    expect(html).toContain('Step 13h &middot; Mark Fully Settled');
    expect(html.indexOf('id="step-13g-benefits"')).toBeLessThan(html.indexOf('id="step-despatch"'));
    expect(html).toContain('Skip — not claiming');
    expect(html).not.toContain('Step 12d');
  });
});
