'use strict';

const db = require('../../src/config/db');
const disputeRepository = require('../../src/repositories/disputeRepository');
const disputeController = require('../../src/controllers/disputeController');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const ordersController = require('../../src/controllers/ordersController');
const stageGateService = require('../../src/services/stageGateService');
const amendmentRepository = require('../../src/repositories/amendmentRepository');
const amendmentService = require('../../src/services/amendmentService');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * QA-4 P0.5: a systematic sweep for manage_*-permission-vs-resource-state
 * gaps — write paths that only ever checked "does this user hold the
 * permission" and never "is the resource actually in a state where this
 * action makes sense." Each of these three is a real, fixed gap (not the
 * Stage-9 closeOrder() finding investigated alongside these, which was left
 * alone: docs/SOP/09-stage9-despatch-closure.md documents that specific
 * behavior as an intentional staff-trust decision, not a bug).
 */
describe('Permission-vs-state sweep (QA-4 P0.5)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => {
      res.redirectedTo = url;
    };
    return res;
  }

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createTestDocument(orderId, typeCode = 'QT') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, 'draft')`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}` }
    );
    return result.insertId;
  }

  async function createTestAmendment(orderId) {
    return amendmentRepository.create(
      `AMD-TEST-${Math.random().toString(16).slice(2, 10)}`,
      orderId,
      'Jest test amendment',
      'importer',
      { note: 'snapshot' },
      null, null, null, null, null, null, null
    );
  }

  // ---------------------------------------------------------------
  // Gap 1: disputeController.updateStatus() took req.body.status straight
  // into the DB with no check against the dispute_status dropdown (a bare
  // VARCHAR(30), not a DB-level enum).
  // ---------------------------------------------------------------

  it('updateStatus refuses a value not in the dispute_status dropdown', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Export Executive');
    const disputeId = await disputeRepository.create(orderId, new Date().toISOString().slice(0, 10), 'Buyer', 'Test dispute', null, null);

    const req = { params: { disputeId: String(disputeId) }, body: { status: 'NotARealStatus' }, user: { id: userId }, session: {} };
    const res = fakeRes();
    await disputeController.updateStatus(req, res);

    const dispute = await disputeRepository.find(disputeId);
    expect(dispute.status).toBe('Open');
    const messages = flash.pull(req);
    expect(messages.length).toBeGreaterThan(0);
    expect(messages[0].type).toBe('error');
  });

  it('updateStatus accepts a value from the dispute_status dropdown', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Export Executive');
    const disputeId = await disputeRepository.create(orderId, new Date().toISOString().slice(0, 10), 'Buyer', 'Test dispute', null, null);

    const req = { params: { disputeId: String(disputeId) }, body: { status: 'Under Review' }, user: { id: userId }, session: {} };
    await disputeController.updateStatus(req, fakeRes());

    const dispute = await disputeRepository.find(disputeId);
    expect(dispute.status).toBe('Under Review');
  });

  // ---------------------------------------------------------------
  // Gap 2: ordersController.saveSupplierPo() never checked Stage 5 was
  // unlocked, unlike confirmSupplierSigned() right below it — holding
  // manage_orders alone was enough to save/generate a Supplier PO for an
  // order still at Stage 1.
  // ---------------------------------------------------------------

  it('saveSupplierPo refuses an order still at Stage 1', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Test Supplier' });

    const req = { params: { id: String(orderId) }, body: { supplier_id: String(supplierId), grade: 'Grade A' }, session: {} };
    const res = fakeRes();
    await ordersController.saveSupplierPo(req, res);

    expect(await orderSupplierPoRepository.findLatestForOrder(orderId)).toBeNull();
    const messages = flash.pull(req);
    expect(messages.length).toBeGreaterThan(0);
    expect(messages[0].type).toBe('error');
    expect(messages[0].message).toContain('Stage 5');
  });

  it('saveSupplierPo succeeds once Stage 5 is unlocked', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Test Supplier' });
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await stageGateService.passAndUnlockNext(orderId, 3, null);
    await stageGateService.passAndUnlockNext(orderId, 4, null);
    expect(await stageGateService.isUnlocked(orderId, 5)).toBe(true);

    const req = { params: { id: String(orderId) }, body: { supplier_id: String(supplierId), grade: 'Grade A' }, session: {} };
    await ordersController.saveSupplierPo(req, fakeRes());

    expect(await orderSupplierPoRepository.findLatestForOrder(orderId)).not.toBeNull();
  });

  // ---------------------------------------------------------------
  // Gap 3: amendmentService.rejectAmendment() never re-checked the
  // amendment's current status, unlike every sibling transition
  // (approveByMd, generateDocument, attachSignedCopyAndActivate) —
  // amendmentRepository.reject() unconditionally overwrites status to
  // 'rejected' with no WHERE-clause guard of its own.
  // ---------------------------------------------------------------

  it('rejectAmendment refuses an already-active amendment', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const amendmentId = await createTestAmendment(orderId);
    await amendmentService.approveByMd(amendmentId, 1);
    const documentId = await createTestDocument(orderId, 'QT');
    await amendmentRepository.attachDocument(amendmentId, documentId);
    const fileId = await createTestFile(orderId, null);
    await amendmentService.attachSignedCopyAndActivate(amendmentId, fileId, 1);
    expect((await amendmentRepository.find(amendmentId)).status).toBe('active');

    await expect(amendmentService.rejectAmendment(amendmentId, 1)).rejects.toThrow('Only a pending amendment can be rejected.');
  });

  it('rejectAmendment succeeds on a pending amendment', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const amendmentId = await createTestAmendment(orderId);

    await amendmentService.rejectAmendment(amendmentId, 1);

    expect((await amendmentRepository.find(amendmentId)).status).toBe('rejected');
  });
});
