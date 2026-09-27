'use strict';

const db = require('../../src/config/db');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const ordersController = require('../../src/controllers/ordersController');
const stageGateService = require('../../src/services/stageGateService');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA-5 (GATE-04/GATE-05/GATE-10 — external QA report cross-verification):
 * a payment must actually be recorded (a non-NULL amount) before it can be
 * marked cleared. The stage-unlock check alone only proves the *previous*
 * gate passed — it says nothing about whether the money itself was ever
 * recorded, so clearing a NULL advance/balance used to still unlock the
 * next stage (and, for the advance, provision client portal access) with
 * zero money having actually been received.
 */
describe('Payment clearance guard (QA-5)', () => {
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

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('clearAdvancePayment refuses an unrecorded advance', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const userId = await createTestUser('Accounts Executive');
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    expect(await stageGateService.isUnlocked(orderId, 3)).toBe(true);

    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await ordersController.clearAdvancePayment(req, fakeRes());

    expect((await orderPaymentStatusRepository.find(orderId)).advance_cleared_at).toBeNull();
    expect(await stageGateService.isUnlocked(orderId, 4)).toBe(false);
    const messages = flash.pull(req);
    expect(messages.length).toBeGreaterThan(0);
    expect(messages[0].type).toBe('error');
  });

  it('clearAdvancePayment succeeds once recorded', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const userId = await createTestUser('Accounts Executive');
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-01-10');

    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await ordersController.clearAdvancePayment(req, fakeRes());

    expect((await orderPaymentStatusRepository.find(orderId)).advance_cleared_at).not.toBeNull();
    expect(await stageGateService.isUnlocked(orderId, 4)).toBe(true);
  });

  it('clearBalancePayment refuses an unrecorded balance', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const userId = await createTestUser('Accounts Executive');
    // Reach Stage 8 WITHOUT going through clearAdvancePayment() (which
    // would auto-populate balance_amount as a side effect) — mirrors an
    // admin stage override reaching Stage 8 some other way.
    for (let stage = 1; stage <= 7; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    expect(await stageGateService.isUnlocked(orderId, 8)).toBe(true);
    expect((await orderPaymentStatusRepository.find(orderId)).balance_amount).toBeNull();

    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await ordersController.clearBalancePayment(req, fakeRes());

    expect((await orderPaymentStatusRepository.find(orderId)).balance_cleared_at).toBeNull();
    expect(await stageGateService.isUnlocked(orderId, 9)).toBe(false);
    const messages = flash.pull(req);
    expect(messages.length).toBeGreaterThan(0);
    expect(messages[0].type).toBe('error');
  });

  it('clearBalancePayment succeeds once recorded', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const userId = await createTestUser('Accounts Executive');
    for (let stage = 1; stage <= 7; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    await orderPaymentStatusRepository.setBalanceAmount(orderId, 1500.0, '2026-03-01');

    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await ordersController.clearBalancePayment(req, fakeRes());

    expect((await orderPaymentStatusRepository.find(orderId)).balance_cleared_at).not.toBeNull();
    expect(await stageGateService.isUnlocked(orderId, 9)).toBe(true);
  });

  it('clearAdvancePayment on a nonexistent order never crashes', async () => {
    const userId = await createTestUser('Accounts Executive');
    const req = { params: { id: '999999' }, body: {}, user: { id: userId }, session: {} };

    await expect(ordersController.clearAdvancePayment(req, fakeRes())).resolves.not.toThrow();
    const messages = flash.pull(req);
    expect(messages.length).toBeGreaterThan(0);
    expect(messages[0].type).toBe('error');
  });
});
