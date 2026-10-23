'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderTravelExpenseRepository = require('../../src/repositories/orderTravelExpenseRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderTravelExpenseTest.php. docs/schema.sql Section BW —
 * export process step 11a: "Record 5/7/10% of the total order value as
 * order expenses - it includes all the travelling expenses for that
 * order". Staff records the factory distance (reference only) and
 * chooses the percentage; the system computes and syncs the amount
 * into order_cost_entries.
 */
describe('Travel expense recording (Section BW)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Travel Expense Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-travel-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null, view: null, locals: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, locals) => { res.view = view; res.locals = locals; };
    return res;
  }

  function fakeReq(overrides = {}) {
    return { params: {}, body: {}, session: {}, permissions: {}, ...overrides };
  }

  async function addProduct(orderId, fobValue) {
    await orderProductRepository.add(orderId, 1, 'Test Product', null, null, '1', false, 'pcs', String(fobValue));
  }

  /**
   * External QA report N-03: every order here is a real export order
   * (currency USD, per createTestOrder's default) with no per-leg
   * exchange rate known until this is called — exactly the real-world
   * state right after order creation, before an advance payment has
   * ever been recorded against it.
   */
  async function setAdvanceRate(orderId, userId, rate = 80.0) {
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', rate, userId);
  }

  // ---------------------------------------------------------------
  // orderTravelExpenseRepository
  // ---------------------------------------------------------------

  it('find returns null before anything recorded', async () => {
    const orderId = await createTestOrder(await createTestClient());
    expect(await orderTravelExpenseRepository.find(orderId)).toBeNull();
  });

  it('upsert creates then updates the same row', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    await orderTravelExpenseRepository.upsert(orderId, { factory_distance_km: 175.0, expense_pct: 5.0, expense_amount: 500.0, cost_entry_id: null, recorded_by: userId });
    const first = await orderTravelExpenseRepository.find(orderId);
    expect(Number(first.expense_pct)).toBe(5);

    await orderTravelExpenseRepository.upsert(orderId, { factory_distance_km: 500.0, expense_pct: 15.0, expense_amount: 1500.0, cost_entry_id: null, recorded_by: userId });
    const second = await orderTravelExpenseRepository.find(orderId);
    expect(Number(second.expense_pct)).toBe(15);
    expect(second.id).toBe(first.id);
  });

  // ---------------------------------------------------------------
  // orderFinancialsController.saveTravelExpenses()
  // ---------------------------------------------------------------

  it('saving a preset percentage computes the amount from FOB value', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await addProduct(orderId, 10000.0);
    await setAdvanceRate(orderId, userId);
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { factory_distance_km: '175', expense_pct: '5' } });

    await orderFinancialsController.saveTravelExpenses(req, fakeRes());

    const row = await orderTravelExpenseRepository.find(orderId);
    expect(Number(row.factory_distance_km)).toBe(175);
    expect(Number(row.expense_pct)).toBe(5);
    expect(Number(row.expense_amount)).toBe(40000);
    expect(flash.pull(req)[0].type).toBe('success');
  });

  it('saving a custom "other" percentage uses the free-text value', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await addProduct(orderId, 10000.0);
    await setAdvanceRate(orderId, userId);
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { expense_pct: 'other', expense_pct_other: '12.5' } });

    await orderFinancialsController.saveTravelExpenses(req, fakeRes());

    const row = await orderTravelExpenseRepository.find(orderId);
    expect(Number(row.expense_pct)).toBe(12.5);
    expect(Number(row.expense_amount)).toBe(100000);
  });

  it('creates a cost entry for the computed amount', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await addProduct(orderId, 20000.0);
    await setAdvanceRate(orderId, userId);
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { expense_pct: '10' } });

    await orderFinancialsController.saveTravelExpenses(req, fakeRes());

    const entries = await orderCostEntryRepository.forOrder(orderId);
    expect(entries.length).toBe(1);
    expect(entries[0].category).toBe('travel_expenses');
    expect(Number(entries[0].amount_inr)).toBe(160000);
  });

  it('resaving updates the same cost entry rather than creating a second one', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await addProduct(orderId, 10000.0);
    await setAdvanceRate(orderId, userId);

    await orderFinancialsController.saveTravelExpenses(fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { expense_pct: '5' } }), fakeRes());
    await orderFinancialsController.saveTravelExpenses(fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { expense_pct: '15' } }), fakeRes());

    const entries = await orderCostEntryRepository.forOrder(orderId);
    expect(entries.length).toBe(1);
    expect(Number(entries[0].amount_inr)).toBe(120000);
  });

  /**
   * External QA report N-03's own repro: a USD order with no exchange
   * rate recorded yet (the real-world state before any advance payment
   * has come in) must refuse to save rather than write pct% of the raw
   * USD FOB value straight into an INR column.
   */
  it("refuses to save when the order's exchange rate is not known yet", async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await addProduct(orderId, 10000.0);
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { expense_pct: '5' } });

    await orderFinancialsController.saveTravelExpenses(req, fakeRes());

    expect(await orderTravelExpenseRepository.find(orderId)).toBeNull();
    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);
    expect(flash.pull(req)[0].type).toBe('error');
  });

  /**
   * Mirrors orderProfitabilityService's own fallback order: the balance
   * leg's rate is used when the advance leg's rate isn't known yet.
   */
  it('falls back to the balance exchange rate when the advance rate is not set', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await addProduct(orderId, 10000.0);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'balance', 90.0, userId);
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { expense_pct: '5' } });

    await orderFinancialsController.saveTravelExpenses(req, fakeRes());

    const row = await orderTravelExpenseRepository.find(orderId);
    expect(Number(row.expense_amount)).toBe(45000);
  });

  it('rejects a zero percentage', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { expense_pct: '0' } });

    await orderFinancialsController.saveTravelExpenses(req, fakeRes());

    expect(await orderTravelExpenseRepository.find(orderId)).toBeNull();
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('rejects a percentage over one hundred', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { expense_pct: '150' } });

    await orderFinancialsController.saveTravelExpenses(req, fakeRes());

    expect(await orderTravelExpenseRepository.find(orderId)).toBeNull();
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('rejects a non-numeric distance', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { expense_pct: '5', factory_distance_km: 'far away' } });

    await orderFinancialsController.saveTravelExpenses(req, fakeRes());

    expect(await orderTravelExpenseRepository.find(orderId)).toBeNull();
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('returns 404 for an unknown order', async () => {
    const userId = await createTestUser();
    const req = fakeReq({ params: { id: '999999999' }, user: { id: userId }, body: { expense_pct: '5' } });
    const res = fakeRes();

    await orderFinancialsController.saveTravelExpenses(req, res);

    expect(res.statusCode).toBe(404);
  });

  // ---------------------------------------------------------------
  // View data — orders/show.njk (via ordersController.show's locals)
  // ---------------------------------------------------------------

  it('show() exposes orderTravelExpenses only with manage_order_financials', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await addProduct(orderId, 10000.0);
    await setAdvanceRate(orderId, userId);
    await orderFinancialsController.saveTravelExpenses(fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { expense_pct: '5' } }), fakeRes());

    const reqWith = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, permissions: { manage_order_financials: true } });
    const resWith = fakeRes();
    await ordersController.show(reqWith, resWith);
    expect(resWith.locals.orderTravelExpenses).not.toBeNull();

    const reqWithout = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, permissions: { manage_order_financials: false } });
    const resWithout = fakeRes();
    await ordersController.show(reqWithout, resWithout);
    expect(resWithout.locals.orderTravelExpenses).toBeNull();
  });

  it('show() returns the saved amount once recorded', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await addProduct(orderId, 10000.0);
    await setAdvanceRate(orderId, userId);
    await orderFinancialsController.saveTravelExpenses(fakeReq({ params: { id: String(orderId) }, user: { id: userId }, body: { factory_distance_km: '175', expense_pct: '5' } }), fakeRes());

    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, permissions: { manage_order_financials: true } });
    const res = fakeRes();
    await ordersController.show(req, res);

    expect(Number(res.locals.orderTravelExpenses.expense_amount)).toBe(40000);
  });
});
