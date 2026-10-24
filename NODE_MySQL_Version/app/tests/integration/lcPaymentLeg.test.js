'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderLcDetailRepository = require('../../src/repositories/orderLcDetailRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const ordersController = require('../../src/controllers/ordersController');
const stageGateService = require('../../src/services/stageGateService');
const flash = require('../../src/helpers/flash');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Step 5 Letter-of-Credit payment leg (order.payment_method = 'LC',
 * snapshotted from payment_presets at order creation — see
 * docs/schema.sql). Record LC Details / Confirm LC Usable replaces
 * Record Advance Remittance / Mark Advance Cleared entirely for an LC
 * order — the two legs are mutually exclusive, same badge letters
 * (5d/5e) either way. No PHP test exists for this leg specifically (the
 * PHP side verified it via a manual full-workflow browser check), so
 * this is a fresh port of that same coverage rather than a line-for-line
 * mirror of an existing PHPUnit file.
 */
describe('Step 5 LC payment leg', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function unlockThrough(orderId, throughStage) {
    for (let stage = 1; stage <= throughStage; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
  }

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('an LC-preset order shows Record LC Details / Confirm LC Usable, never the cash-advance leg', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Irrevocable Letter of Credit at Sight');
    await unlockThrough(orderId, 2);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('Step 5d &middot; Record LC Details');
    expect(res.text).toContain('Step 5e &middot; Confirm LC Usable');
    expect(res.text).not.toContain('Record Advance Remittance');
    expect(res.text).not.toContain('Mark Advance Cleared');
    expect(res.text).toContain('payment method for this order: <strong>Letter of Credit</strong>');
  });

  it('a Cash Advance order never shows the LC leg', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer');
    await unlockThrough(orderId, 2);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('Step 5d &middot; Record Advance Remittance');
    expect(res.text).toContain('Step 5e &middot; Mark Advance Cleared');
    expect(res.text).not.toContain('Record LC Details');
    expect(res.text).not.toContain('Confirm LC Usable');
    expect(res.text).toContain('payment method for this order: <strong>Cash Advance</strong>');
  });

  it('recordLcDetails then confirmLcUsable unlocks Stage 4 and stamps lc_confirmed_at', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Irrevocable Letter of Credit at Sight');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const userId = await createTestUser('Accounts Executive');
    await unlockThrough(orderId, 2);
    expect(await stageGateService.isUnlocked(orderId, 3)).toBe(true);

    const recordReq = {
      params: { id: String(orderId) },
      body: { lc_bank_name: 'Jest Test Bank', lc_number: 'LC-JEST-001', lc_amount: '10000', lc_charges_amount: '150' },
      user: { id: userId },
      session: {},
    };
    await ordersController.recordLcDetails(recordReq, fakeRes());

    const afterRecord = await orderLcDetailRepository.find(orderId);
    expect(afterRecord).not.toBeNull();
    expect(parseFloat(afterRecord.lc_amount)).toBe(10000);
    expect(afterRecord.lc_confirmed_at).toBeNull();

    const confirmReq = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await ordersController.confirmLcUsable(confirmReq, fakeRes());

    const afterConfirm = await orderLcDetailRepository.find(orderId);
    expect(afterConfirm.lc_confirmed_at).not.toBeNull();
    expect(await stageGateService.isUnlocked(orderId, 4)).toBe(true);
  });

  it('confirmLcUsable refuses when LC details have not been recorded yet', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Irrevocable Letter of Credit at Sight');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const userId = await createTestUser('Accounts Executive');
    await unlockThrough(orderId, 2);
    expect(await stageGateService.isUnlocked(orderId, 3)).toBe(true);

    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await ordersController.confirmLcUsable(req, fakeRes());

    expect(await stageGateService.isUnlocked(orderId, 4)).toBe(false);
    const messages = flash.pull(req);
    expect(messages.length).toBeGreaterThan(0);
    expect(messages[0].type).toBe('error');
  });

  it('recordLcDetails refuses a zero/blank LC amount', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Irrevocable Letter of Credit at Sight');
    const userId = await createTestUser('Accounts Executive');
    await unlockThrough(orderId, 2);

    const req = { params: { id: String(orderId) }, body: { lc_amount: '' }, user: { id: userId }, session: {} };
    await ordersController.recordLcDetails(req, fakeRes());

    expect(await orderLcDetailRepository.find(orderId)).toBeNull();
    const messages = flash.pull(req);
    expect(messages.length).toBeGreaterThan(0);
    expect(messages[0].type).toBe('error');
  });
});
