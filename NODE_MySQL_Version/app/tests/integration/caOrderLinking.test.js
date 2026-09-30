'use strict';

const db = require('../../src/config/db');
const caExpenseRepository = require('../../src/repositories/caExpenseRepository');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const caController = require('../../src/controllers/caController');
const ordersController = require('../../src/controllers/ordersController');
const orderRepository = require('../../src/repositories/orderRepository');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CaOrderLinkingTest.php. Point 2 follow-up: a RODTEP/export-
 * benefit claim or an expense (ECGC insurance, third-party inspection,
 * CHA, transport, ...) can be linked to the specific order it relates
 * to, and the order's own detail page shows every linked benefit/expense
 * at a glance — not just the separate CA module list screens.
 */
describe('CA order linking (Point 2 follow-up)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  it('expenses forOrder returns only that order\'s expenses', async () => {
    const orderIdA = await createTestOrder(await createTestClient());
    const orderIdB = await createTestOrder(await createTestClient());

    const expenseId = await caExpenseRepository.insert('ZOHO-JLINK-1', 'ECGC insurance', 'Shipment cover', 'ECGC', 5000, 'INR', '2026-06-01');
    await caExpenseRepository.insert('ZOHO-JLINK-2', 'CHA', 'Unrelated expense', 'Some CHA', 1000, 'INR', '2026-06-01');

    expect(await caExpenseRepository.forOrder(orderIdA)).toEqual([]);

    await caExpenseRepository.linkToOrder(expenseId, orderIdA);

    const forA = await caExpenseRepository.forOrder(orderIdA);
    expect(forA).toHaveLength(1);
    expect(forA[0].id).toBe(expenseId);
    expect(await caExpenseRepository.forOrder(orderIdB)).toEqual([]);
  });

  it('linkToOrder can be undone by passing null', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const expenseId = await caExpenseRepository.insert('ZOHO-JLINK-3', 'Transport', null, 'Some Transporter', 2000, 'INR', '2026-06-01');

    await caExpenseRepository.linkToOrder(expenseId, orderId);
    expect(await caExpenseRepository.forOrder(orderId)).toHaveLength(1);

    await caExpenseRepository.linkToOrder(expenseId, null);
    expect(await caExpenseRepository.forOrder(orderId)).toEqual([]);
  });

  it('all() joins in the linked order reference', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const order = await orderRepository.find(orderId);
    const expenseId = await caExpenseRepository.insert('ZOHO-JLINK-4', 'Inspection', null, 'SGS', 3000, 'INR', '2026-06-01');
    await caExpenseRepository.linkToOrder(expenseId, orderId);

    const all = await caExpenseRepository.all();
    const found = all.find((r) => r.id === expenseId);
    expect(found.order_reference).toBe(order.order_reference);
  });

  it('export benefit forOrder returns only that order\'s claims', async () => {
    const userId = await createTestUser();
    const orderIdA = await createTestOrder(await createTestClient());
    const orderIdB = await createTestOrder(await createTestClient());

    await caExportBenefitRepository.record(orderIdA, 'RODTEP', 'SB-JLINK-1', 8000, '2026-06-01', 'INR', null, userId);
    await caExportBenefitRepository.record(orderIdB, 'RODTEP', 'SB-JLINK-2', 4000, '2026-06-01', 'INR', null, userId);
    await caExportBenefitRepository.record(null, 'RODTEP', 'SB-JLINK-3', 1000, '2026-06-01', 'INR', null, userId);

    const forA = await caExportBenefitRepository.forOrder(orderIdA);
    expect(forA).toHaveLength(1);
    expect(forA[0].reference_number).toBe('SB-JLINK-1');
  });

  it('linkExpenseToOrder controller links by valid reference', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const order = await orderRepository.find(orderId);
    const expenseId = await caExpenseRepository.insert('ZOHO-JLINK-5', 'ECGC insurance', null, 'ECGC', 6000, 'INR', '2026-06-01');

    const req = { params: { id: String(expenseId) }, body: { order_reference: order.order_reference }, user: { id: userId }, session: {} };
    await caController.linkExpenseToOrder(req, fakeRes());

    const expense = await caExpenseRepository.find(expenseId);
    expect(expense.order_id).toBe(orderId);

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'CA_EXPENSE_LINKED_TO_ORDER' AND entity_id = :id",
      { id: expenseId }
    );
    expect(parseInt(row.c, 10)).toBe(1);
  });

  it('linkExpenseToOrder controller rejects an unknown reference', async () => {
    const userId = await createTestUser();
    const expenseId = await caExpenseRepository.insert('ZOHO-JLINK-6', 'CHA', null, 'Some CHA', 1500, 'INR', '2026-06-01');

    const req = { params: { id: String(expenseId) }, body: { order_reference: 'NO-SUCH-ORDER-REF-999' }, user: { id: userId }, session: {} };
    await caController.linkExpenseToOrder(req, fakeRes());

    const expense = await caExpenseRepository.find(expenseId);
    expect(expense.order_id).toBeNull();
  });

  it('linkExpenseToOrder controller unlinks on a blank reference', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const expenseId = await caExpenseRepository.insert('ZOHO-JLINK-7', 'Transport', null, 'Some Transporter', 2500, 'INR', '2026-06-01');
    await caExpenseRepository.linkToOrder(expenseId, orderId);

    const req = { params: { id: String(expenseId) }, body: { order_reference: '' }, user: { id: userId }, session: {} };
    await caController.linkExpenseToOrder(req, fakeRes());

    const expense = await caExpenseRepository.find(expenseId);
    expect(expense.order_id).toBeNull();

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'CA_EXPENSE_UNLINKED_FROM_ORDER' AND entity_id = :id",
      { id: expenseId }
    );
    expect(parseInt(row.c, 10)).toBe(1);
  });

  it('order show page passes linked benefits and expenses to the view', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-JSHOW-1', 9000, '2026-06-01', 'INR', null, userId);
    const expenseId = await caExpenseRepository.insert('ZOHO-JSHOW-1', 'ECGC insurance', null, 'ECGC', 4500, 'INR', '2026-06-01');
    await caExpenseRepository.linkToOrder(expenseId, orderId);

    const permissions = new Proxy({}, { get: () => true });
    const req = { params: { id: String(orderId) }, permissions, session: {} };
    const res = fakeRes();
    await ordersController.show(req, res);

    expect(res.renderedData.canViewCaLinks).toBe(true);
    expect(res.renderedData.linkedExportBenefits).toHaveLength(1);
    expect(res.renderedData.linkedExportBenefits[0].reference_number).toBe('SB-JSHOW-1');
    expect(res.renderedData.linkedCaExpenses).toHaveLength(1);
    expect(res.renderedData.linkedCaExpenses[0].category).toBe('ECGC insurance');
  });

  it('order show page returns no linked data when the permission is absent', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const req = { params: { id: String(orderId) }, permissions: {}, session: {} };
    const res = fakeRes();
    await ordersController.show(req, res);

    expect(res.renderedData.canViewCaLinks).toBe(false);
    expect(res.renderedData.linkedExportBenefits).toEqual([]);
    expect(res.renderedData.linkedCaExpenses).toEqual([]);
  });
});
