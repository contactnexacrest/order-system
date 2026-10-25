'use strict';

const ordersController = require('../../src/controllers/ordersController');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const orderBlEndorsementRepository = require('../../src/repositories/orderBlEndorsementRepository');
const documentRepository = require('../../src/repositories/documentRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const stageGateService = require('../../src/services/stageGateService');
const flash = require('../../src/helpers/flash');
const db = require('../../src/config/db');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderPageStageGateFixesTest.php. Order detail page polish
 * round: investigation while fixing the step-card UI found three real
 * server-side gaps, independent of any view change — a direct POST
 * (holding only the ordinary manage_shipping/manage_supplier_payments
 * permission) on an order still at Stage 1 could save freight terms,
 * mark the supplier paid, or record/endorse BL originals, fully
 * skipping Stages 2-8. Every other stage-advancing action already
 * called stageGateService.isUnlocked() before writing — these seven
 * actions did not. This covers the "refuses while locked" half of the
 * fix; the "still works once unlocked" half is already exercised by
 * the (now-updated) existing tests in supplierPaymentDates.test.js,
 * n08AuditLogCoverageClusterD.test.js, and blEndorsement.test.js.
 */
describe('Order-page stage gate fixes', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Stage Gate Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-stagegate-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createTestSupplierPo(orderId) {
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Stage Gate Test Supplier Co' });
    return orderSupplierPoRepository.create(orderId, supplierId, 'STAGEGATE-SPO-TEST', {
      advance_amount_inr: '20000', balance_amount_inr: '30000',
    }, 'issued');
  }

  function fakeReq(orderId, userId, body = {}) {
    return { params: { id: String(orderId) }, body, user: { id: userId }, session: {} };
  }

  function fakeRes() {
    const res = {};
    res.redirect = () => {};
    res.status = () => res;
    res.send = () => {};
    return res;
  }

  // ---------------------------------------------------------------
  // saveFreightTerms() — Stage 6
  // ---------------------------------------------------------------

  it('saveFreightTerms refuses on a fresh order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq(orderId, null, { confirmed_freight_rate: '500' });

    await ordersController.saveFreightTerms(req, fakeRes());

    const f = flash.pull(req);
    expect(f[0].type).toBe('error');
    expect(f[0].message).toMatch(/Stage 6/);
    expect(await orderFreightRepository.find(orderId)).toBeNull();
  });

  // ---------------------------------------------------------------
  // markSupplierAdvancePaid() / markSupplierBalancePaid() — Stage 5
  // ---------------------------------------------------------------

  it('markSupplierAdvancePaid refuses on a fresh order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestSupplierPo(orderId);
    const userId = await createTestUser();
    const req = fakeReq(orderId, userId, { paid_date: '2026-10-20', amount_paid_inr: '20000' });

    await ordersController.markSupplierAdvancePaid(req, fakeRes());

    const f = flash.pull(req);
    expect(f[0].type).toBe('error');
    expect(f[0].message).toMatch(/Stage 5/);
    expect((await orderSupplierPoRepository.findLatestForOrder(orderId)).advance_paid_at).toBeFalsy();
  });

  it('markSupplierBalancePaid refuses on a fresh order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestSupplierPo(orderId);
    const userId = await createTestUser();
    const req = fakeReq(orderId, userId, { paid_date: '2026-10-20', amount_paid_inr: '30000' });

    await ordersController.markSupplierBalancePaid(req, fakeRes());

    const f = flash.pull(req);
    expect(f[0].type).toBe('error');
    expect(f[0].message).toMatch(/Stage 5/);
    expect((await orderSupplierPoRepository.findLatestForOrder(orderId)).balance_paid_at).toBeFalsy();
  });

  // ---------------------------------------------------------------
  // recordBlOriginalsReceived() / recordBlEndorsed() / saveBlEndorsement() / generateBlEndorsement() — Stage 9
  // ---------------------------------------------------------------

  it('recordBlOriginalsReceived refuses on a fresh order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq(orderId, null, { bl_originals_count: '3' });

    await ordersController.recordBlOriginalsReceived(req, fakeRes());

    const f = flash.pull(req);
    expect(f[0].type).toBe('error');
    expect(f[0].message).toMatch(/Stage 9/);
    const shipping = await orderShippingRepository.find(orderId);
    expect(!shipping || shipping.bl_originals_received_at == null).toBe(true);
  });

  it('recordBlEndorsed refuses on a fresh order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    const req = fakeReq(orderId, userId);

    await ordersController.recordBlEndorsed(req, fakeRes());

    const f = flash.pull(req);
    expect(f[0].type).toBe('error');
    expect(f[0].message).toMatch(/Stage 9/);
    const shipping = await orderShippingRepository.find(orderId);
    expect(!shipping || shipping.bl_endorsed_at == null).toBe(true);
  });

  it('saveBlEndorsement refuses on a fresh order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    const req = fakeReq(orderId, userId, { bl_number: 'MSCU1234567' });

    await ordersController.saveBlEndorsement(req, fakeRes());

    const f = flash.pull(req);
    expect(f[0].type).toBe('error');
    expect(f[0].message).toMatch(/Stage 9/);
    expect(await orderBlEndorsementRepository.find(orderId)).toBeNull();
  });

  it('generateBlEndorsement refuses on a fresh order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    await orderBlEndorsementRepository.upsert(orderId, { bl_number: 'MSCU1234567' }, userId);
    const req = fakeReq(orderId, userId);

    await ordersController.generateBlEndorsement(req, fakeRes());

    const f = flash.pull(req);
    expect(f[0].type).toBe('error');
    expect(f[0].message).toMatch(/Stage 9/);
    expect(await documentRepository.findLatestForOrderAndTypeCode(orderId, 'BLE')).toBeNull();
  });

  // ---------------------------------------------------------------
  // Sanity check — the gate itself resolves true once genuinely unlocked
  // ---------------------------------------------------------------

  it('all three gates resolve unlocked once their stage is reached', async () => {
    // isUnlocked() means "this stage is the order's current, in-progress
    // stage" — not "has been reached and passed" — so each stage is
    // checked on a fresh order advanced to exactly that point, not one
    // order walked straight through to the end. 3 orders x up to 8
    // sequential passAndUnlockNext() calls each (each firing its own
    // notification email) genuinely exceeds the default 20s budget under
    // a full-suite run with the DB under load from every other file.
    const stage5Order = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 4; stage++) {
      await stageGateService.passAndUnlockNext(stage5Order, stage, null);
    }
    expect(await stageGateService.isUnlocked(stage5Order, 5)).toBe(true);

    const stage6Order = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 5; stage++) {
      await stageGateService.passAndUnlockNext(stage6Order, stage, null);
    }
    expect(await stageGateService.isUnlocked(stage6Order, 6)).toBe(true);

    const stage9Order = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 8; stage++) {
      await stageGateService.passAndUnlockNext(stage9Order, stage, null);
    }
    expect(await stageGateService.isUnlocked(stage9Order, 9)).toBe(true);
  }, 60000);
});
