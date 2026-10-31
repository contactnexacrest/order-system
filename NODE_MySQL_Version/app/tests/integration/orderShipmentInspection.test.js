'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const orderRepository = require('../../src/repositories/orderRepository');
const orderCommentRepository = require('../../src/repositories/orderCommentRepository');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
const orderInspectionFeeRepository = require('../../src/repositories/orderInspectionFeeRepository');
const stageGateService = require('../../src/services/stageGateService');
const notificationRepository = require('../../src/repositories/notificationRepository');
const userRepository = require('../../src/repositories/userRepository');
const { run } = require('../../src/jobs/checkAlerts');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL } = require('../support/globalSetup');

/**
 * Port of OrderShipmentInspectionTest.php. docs/schema.sql Section BT —
 * export process step 8 (estimated shipment date update notifies the
 * client, visible in their portal) and step 8a (7-day pre-shipment
 * inspection reminder + inspection fee payer/amount recorded as an
 * order expense when NexaCrest pays).
 */
describe('Shipment date + pre-shipment inspection (Section BT)', () => {
  let adminId;

  beforeAll(async () => {
    const admin = await userRepository.findByEmail(TEST_ADMIN_EMAIL);
    adminId = admin.id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Shipment Inspection Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-ship-insp-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  function fakeReq(overrides = {}) {
    return { params: {}, body: {}, session: {}, permissions: {}, ...overrides };
  }

  async function unlockThrough(orderId, throughStage) {
    for (let stage = 1; stage <= throughStage; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
  }

  // ---------------------------------------------------------------
  // ordersController.updateProductionStatus() — step 8
  // ---------------------------------------------------------------

  it('updating the shipment text and date notifies the client via an order comment', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient('client@example.test'));
    await unlockThrough(orderId, 3);
    const req = fakeReq({
      user: { id: userId },
      params: { id: String(orderId) },
      body: { est_shipment_date_text: 'Week of 15 November 2026', est_shipment_date: '2026-11-15' },
    });

    await ordersController.updateProductionStatus(req, fakeRes());

    const order = await orderRepository.find(orderId);
    expect(order.est_shipment_date_text).toBe('Week of 15 November 2026');
    expect(String(order.est_shipment_date).slice(0, 10)).toBe('2026-11-15');

    const comments = await orderCommentRepository.forOrder(orderId);
    expect(comments.length).toBeGreaterThan(0);
    expect(comments[0].body).toMatch(/15 Nov 2026/);
    expect(comments[0].author_type).toBe('staff');
  });

  it('updating only the text does not clobber a previously-set structured date', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 3);
    await orderRepository.setEstShipmentDate(orderId, 'Week of 1 November 2026', '2026-11-01');

    const req = fakeReq({
      user: { id: userId },
      params: { id: String(orderId) },
      body: { est_shipment_date_text: 'Week of 1 November 2026 — confirmed', est_shipment_date: '' },
    });
    await ordersController.updateProductionStatus(req, fakeRes());

    const order = await orderRepository.find(orderId);
    expect(order.est_shipment_date_text).toBe('Week of 1 November 2026 — confirmed');
    expect(String(order.est_shipment_date).slice(0, 10)).toBe('2026-11-01');
  });

  it('makes no update when neither field actually changes', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 3);
    await orderRepository.setEstShipmentDate(orderId, 'Week of 1 November 2026', '2026-11-01');

    const req = fakeReq({
      user: { id: userId },
      params: { id: String(orderId) },
      body: { est_shipment_date_text: 'Week of 1 November 2026', est_shipment_date: '2026-11-01' },
    });
    await ordersController.updateProductionStatus(req, fakeRes());

    expect(await orderCommentRepository.forOrder(orderId)).toEqual([]);
  });

  // ---------------------------------------------------------------
  // orderInspectionFeeRepository
  // ---------------------------------------------------------------

  it('upsert creates then updates the same row', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    await orderInspectionFeeRepository.upsert(orderId, { fee_payer: 'client', fee_amount: null, cost_entry_id: null, recorded_by: userId });
    const first = await orderInspectionFeeRepository.find(orderId);
    expect(first.fee_payer).toBe('client');

    await orderInspectionFeeRepository.upsert(orderId, { fee_payer: 'nexacrest', fee_amount: 1500, cost_entry_id: null, recorded_by: userId });
    const second = await orderInspectionFeeRepository.find(orderId);
    expect(second.fee_payer).toBe('nexacrest');
    expect(Number(second.id)).toBe(Number(first.id));

    const row = await db.queryOne('SELECT COUNT(*) AS c FROM order_inspection_fees WHERE order_id = :id', { id: orderId });
    expect(Number(row.c)).toBe(1);
  });

  // ---------------------------------------------------------------
  // orderFinancialsController.saveInspectionFee()
  // ---------------------------------------------------------------

  it('rejects an invalid payer', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { fee_payer: 'someone-else' } });

    await orderFinancialsController.saveInspectionFee(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
    expect(await orderInspectionFeeRepository.find(orderId)).toBeNull();
  });

  it('a NexaCrest-paid fee must say what it was paid from', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { fee_payer: 'nexacrest', fee_amount: '2500' } });

    await orderFinancialsController.saveInspectionFee(req, fakeRes());

    expect(flash.pull(req)[0].message).toBe('Choose what the inspection fee was paid from.');
    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);
  });

  // Owner rule D-212 (Batch 2): the fee names the agency and its invoice, and the cost line is described from them.
  it('a NexaCrest-paid fee names the agency and its invoice', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const post = { fee_payer: 'nexacrest', fee_amount: '2500', paid_from: 'company_account' };
    const save = async (body) => {
      const req = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body });
      await orderFinancialsController.saveInspectionFee(req, fakeRes());
      return flash.pull(req)[0].message;
    };

    expect(await save({ ...post, agency_invoice_no: 'SGS/MUM/26/0457' })).toBe('Enter the inspection agency that was paid (2 to 150 characters).');
    expect(await save({ ...post, agency_name: 'SGS India Pvt Ltd' })).toBe("Enter the inspection agency's invoice no. (up to 60 characters).");
    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]); // nothing saved without them

    await save({ ...post, agency_name: 'SGS India Pvt Ltd', agency_invoice_no: 'SGS/MUM/26/0457' });
    expect((await orderCostEntryRepository.forOrder(orderId))[0].description).toBe('Pre-shipment inspection fee — SGS India Pvt Ltd, invoice SGS/MUM/26/0457');
    const fee = await orderInspectionFeeRepository.find(orderId);
    expect([fee.agency_name, fee.agency_invoice_no]).toEqual(['SGS India Pvt Ltd', 'SGS/MUM/26/0457']);

    expect(await save({ fee_payer: 'client' })).toContain('saved'); // the client pays: nothing to name
  });

  it('nexacrest payer with an amount creates a matching cost entry', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { fee_payer: 'nexacrest', fee_amount: '2500', paid_from: 'company_account', agency_name: 'SGS India Pvt Ltd', agency_invoice_no: 'SGS/MUM/26/0457' } });

    await orderFinancialsController.saveInspectionFee(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('success');
    const entries = await orderCostEntryRepository.forOrder(orderId);
    expect(entries.length).toBe(1);
    expect(entries[0].category).toBe('inspection_fee');
    expect(Number(entries[0].amount_inr)).toBe(2500);
    expect(entries[0].paid_from).toBe('company_account'); // owner decision 2026-10-29: what it was paid from, for Zoho Books

    const saved = await orderInspectionFeeRepository.find(orderId);
    expect(Number(saved.cost_entry_id)).toBe(Number(entries[0].id));
  });

  it('client payer never creates a cost entry and removes an existing one', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req1 = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { fee_payer: 'nexacrest', fee_amount: '2500', paid_from: 'company_account', agency_name: 'SGS India Pvt Ltd', agency_invoice_no: 'SGS/MUM/26/0457' } });
    await orderFinancialsController.saveInspectionFee(req1, fakeRes());
    expect((await orderCostEntryRepository.forOrder(orderId)).length).toBe(1);

    const req2 = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { fee_payer: 'client' } });
    await orderFinancialsController.saveInspectionFee(req2, fakeRes());

    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);
    expect((await orderInspectionFeeRepository.find(orderId)).cost_entry_id).toBeNull();
  });

  it('re-saving nexacrest with a different amount updates the same cost entry', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req1 = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { fee_payer: 'nexacrest', fee_amount: '2500', paid_from: 'company_account', agency_name: 'SGS India Pvt Ltd', agency_invoice_no: 'SGS/MUM/26/0457' } });
    await orderFinancialsController.saveInspectionFee(req1, fakeRes());
    const firstEntryId = Number((await orderCostEntryRepository.forOrder(orderId))[0].id);

    const req2 = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { fee_payer: 'nexacrest', fee_amount: '3000', paid_from: 'company_account', agency_name: 'SGS India Pvt Ltd', agency_invoice_no: 'SGS/MUM/26/0457' } });
    await orderFinancialsController.saveInspectionFee(req2, fakeRes());

    const entries = await orderCostEntryRepository.forOrder(orderId);
    expect(entries.length).toBe(1);
    expect(Number(entries[0].id)).toBe(firstEntryId);
    expect(Number(entries[0].amount_inr)).toBe(3000);
  });

  it('returns 404 for an unknown order', async () => {
    const userId = await createTestUser();
    const req = fakeReq({ user: { id: userId }, params: { id: '999999999' } });
    const res = fakeRes();

    await orderFinancialsController.saveInspectionFee(req, res);

    expect(res.statusCode).toBe(404);
  });

  // ---------------------------------------------------------------
  // View — form/section gating
  // ---------------------------------------------------------------

  it('the order page offers the structured shipment date field', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 3);
    const userId = await createTestUser();
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, permissions: new Proxy({}, { get: () => true }) });
    let rendered = null;
    const res = fakeRes();
    res.renderView = (view, locals) => { rendered = locals; };

    await ordersController.show(req, res);

    expect(rendered.pendingInspectionTask).toBe(true);
  });

  // ---------------------------------------------------------------
  // checkAlerts.js — the 7-day reminder itself
  // ---------------------------------------------------------------

  it('alerts when estimated shipment is within the next 7 days and inspection is not skipped', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute('UPDATE orders SET est_shipment_date = DATE_ADD(CURDATE(), INTERVAL 5 DAY) WHERE id = :id', { id: orderId });

    await run();

    expect(await notificationRepository.existsToday(adminId, 'pre_shipment_inspection_reminder', orderId)).toBe(true);
  });

  it('does not alert when estimated shipment is more than 7 days out', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute('UPDATE orders SET est_shipment_date = DATE_ADD(CURDATE(), INTERVAL 10 DAY) WHERE id = :id', { id: orderId });

    await run();

    expect(await notificationRepository.existsToday(adminId, 'pre_shipment_inspection_reminder', orderId)).toBe(false);
  });

  it('does not alert once the estimated shipment date has already passed', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute('UPDATE orders SET est_shipment_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = :id', { id: orderId });

    await run();

    expect(await notificationRepository.existsToday(adminId, 'pre_shipment_inspection_reminder', orderId)).toBe(false);
  });

  it('does not alert when the Pre-Shipment Inspection task is marked skipped', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute('UPDATE orders SET est_shipment_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY) WHERE id = :id', { id: orderId });
    const taskType = await db.queryOne("SELECT id FROM compliance_task_types WHERE name = 'Pre-Shipment Inspection'");
    await orderComplianceTaskRepository.setStatus(orderId, taskType.id, 'skipped', 'No 3rd-party inspection required', adminId);

    await run();

    expect(await notificationRepository.existsToday(adminId, 'pre_shipment_inspection_reminder', orderId)).toBe(false);
  });

  it('does not alert for a locked order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute("UPDATE orders SET est_shipment_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY), is_locked = 1, status = 'lost' WHERE id = :id", { id: orderId });

    await run();

    expect(await notificationRepository.existsToday(adminId, 'pre_shipment_inspection_reminder', orderId)).toBe(false);
  });

  it('does not alert when no estimated shipment date is set', async () => {
    const orderId = await createTestOrder(await createTestClient());
    // createTestOrder() leaves est_shipment_date NULL by default.

    await run();

    expect(await notificationRepository.existsToday(adminId, 'pre_shipment_inspection_reminder', orderId)).toBe(false);
  });

  /**
   * External QA report N-09: notifyMdAndAdmin() used to call
   * emailService.sendPlainText() directly right after
   * notificationRepository.create(), which already sends its own email
   * via emailNotification() internally — every recipient of every alert
   * type in this job (not just this one reminder) got two differently-
   * worded emails for the same event. Whitebox — not otherwise
   * observable without mocking emailService.
   */
  it('notifyMdAndAdmin no longer sends a duplicate email directly', () => {
    const source = fs.readFileSync(path.join(__dirname, '../../src/jobs/checkAlerts.js'), 'utf8');
    const functionBody = source.slice(source.indexOf('async function notifyMdAndAdmin'), source.indexOf('async function notifyMdAndAdmin') + 400);

    expect(functionBody).not.toContain('emailService.sendPlainText');
  });
});
