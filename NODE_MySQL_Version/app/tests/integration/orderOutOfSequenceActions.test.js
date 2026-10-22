'use strict';

const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const orderPackingRepository = require('../../src/repositories/orderPackingRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderOutOfSequenceActionsTest.php. External QA report L-35
 * (OOS-01): "Actions are accepted out of sequence: on an order still at
 * Stage 1 the app stores shipping details (vessel, container), a
 * freight payment, and the advance FIRC, exchange rate and
 * INR-credited amount (the advance itself was never recorded)." Each
 * handler below now checks either the stage it actually belongs to
 * (recordFreightPayment/savePacking/saveShipping, matching the gate
 * checks clearFreightPayment/recordBlIssued already use) or the
 * prerequisite record it depends on (recordAdvanceFirc/
 * recordLegExchangeRate/recordAdvanceInrActual and their balance/
 * freight siblings — the leg's own *_amount must be recorded first).
 */
describe('Order out-of-sequence actions (External QA report L-35)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-oos-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  async function baseReq(orderId) {
    return { params: { id: String(orderId) }, session: {}, user: { id: await createTestUser('Admin') }, permissions: {}, body: {} };
  }

  it('recordFreightPayment refuses on an order still at Stage 1', async () => {
    const orderId = await createTestOrder(await createTestClient());
    // Initialized (not left absent) so the UPDATE below would actually
    // succeed if the stage check didn't block it first — otherwise this
    // test would pass vacuously (the UPDATE is a no-op against a
    // nonexistent row regardless of the fix).
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const req = await baseReq(orderId);
    req.body = { freight_amount: '500' };

    await ordersController.recordFreightPayment(req, fakeRes());

    const status = await orderPaymentStatusRepository.find(orderId);
    expect((status && status.freight_amount) || null).toBeNull();
  });

  it('saveShipping refuses on an order still at Stage 1', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const req = await baseReq(orderId);
    req.body = { vessel_name: 'MV Test Vessel' };

    await ordersController.saveShipping(req, fakeRes());

    const shipping = await orderShippingRepository.find(orderId);
    expect((shipping && shipping.vessel_name) || null).toBeNull();
  });

  it('savePacking refuses on an order still at Stage 1', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const req = await baseReq(orderId);
    req.body = { crate_count: '3' };

    await ordersController.savePacking(req, fakeRes());

    const packing = await orderPackingRepository.find(orderId);
    expect(packing).toBeFalsy();
  });

  it('recordAdvanceFirc refuses when the advance itself was never recorded', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const req = await baseReq(orderId);
    req.body = { advance_firc_reference: 'FIRC-12345' };

    await ordersController.recordAdvanceFirc(req, fakeRes());

    const status = await orderPaymentStatusRepository.find(orderId);
    expect((status && status.advance_firc_reference) || null).toBeNull();
  });

  it('recordAdvanceFirc succeeds once the advance is recorded', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 5000.0, '2026-01-10');
    const req = await baseReq(orderId);
    req.body = { advance_firc_reference: 'FIRC-12345' };

    await ordersController.recordAdvanceFirc(req, fakeRes());

    const status = await orderPaymentStatusRepository.find(orderId);
    expect(status.advance_firc_reference).toBe('FIRC-12345');
  });

  it('recordFreightExchangeRate refuses when the freight payment was never recorded', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const req = await baseReq(orderId);
    req.body = { freight_exchange_rate: '83.25' };

    await ordersController.recordFreightExchangeRate(req, fakeRes());

    const status = await orderPaymentStatusRepository.find(orderId);
    expect((status && status.freight_exchange_rate) || null).toBeNull();
  });

  it('recordFreightExchangeRate succeeds once the freight payment is recorded', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordFreightReceived(orderId, 800.0, '2026-01-10');
    const req = await baseReq(orderId);
    req.body = { freight_exchange_rate: '83.25' };

    await ordersController.recordFreightExchangeRate(req, fakeRes());

    const status = await orderPaymentStatusRepository.find(orderId);
    expect(Number(status.freight_exchange_rate)).toBe(83.25);
  });

  it('recordBalanceInrActual refuses when the balance payment was never recorded', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const req = await baseReq(orderId);
    req.body = { balance_inr_actual: '415000' };

    await ordersController.recordBalanceInrActual(req, fakeRes());

    const status = await orderPaymentStatusRepository.find(orderId);
    expect((status && status.balance_inr_actual) || null).toBeNull();
  });

  it('recordBalanceInrActual succeeds once the balance payment is recorded', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordBalanceReceived(orderId, 5000.0, '2026-01-10');
    const req = await baseReq(orderId);
    req.body = { balance_inr_actual: '415000' };

    await ordersController.recordBalanceInrActual(req, fakeRes());

    const status = await orderPaymentStatusRepository.find(orderId);
    expect(Number(status.balance_inr_actual)).toBe(415000);
  });
});
