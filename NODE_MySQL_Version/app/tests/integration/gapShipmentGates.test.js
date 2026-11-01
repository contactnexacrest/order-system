'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const shipmentGateService = require('../../src/services/shipmentGateService');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of GapShipmentGatesTest.php. Gap list answers (owner, 1 Nov 2026),
 * part A: G-1 a "before shipment" balance is recorded and cleared before
 * the Bill of Lading; G-3 the pre-shipment inspection must be Passed; a
 * manager releases the BL step with a reason; G-6 with e-mail off, the
 * buyer's 48 hours start when staff mark the OC sent.
 */
describe('Gap list G-1 / G-3 / G-6: shipment gates and the OC clock', () => {
  let admin;

  afterAll(async () => {
    await companySettingsRepository.set('mail_sending_enabled', '0');
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Gap Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-gap-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id };
  }

  async function createTestDocument(orderId, typeCode, status) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      'INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :t, :r, :s)',
      { o: orderId, t: type.id, r: `JEST-GAP-${Math.random().toString(16).slice(2, 10)}`, s: status }
    );
    return result.insertId;
  }

  async function permissionsOf(roleId) {
    const rows = await db.query('SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r', { r: roleId });
    return Object.fromEntries(rows.map((r) => [r.permission_key, true]));
  }

  /** Runs one action; returns the flash message. */
  async function call(action, orderId, body = {}, user = admin, file = null) {
    const req = { user: { id: user.id, role_id: user.roleId }, permissions: await permissionsOf(user.roleId), body, params: { id: String(orderId) }, session: {}, file };
    const res = { redirect: () => {}, renderView: () => {} };
    await ordersController[action](req, res);
    return ((req.session._flash || [])[0] || {}).message || '';
  }

  async function showData(orderId, user = admin) {
    const req = { user: { id: user.id, role_id: user.roleId }, permissions: await permissionsOf(user.roleId), params: { id: String(orderId) }, session: {}, query: {} };
    const res = { redirect: () => {}, renderView: (view, data) => { res.data = data; } };
    await ordersController.show(req, res);
    return res.data;
  }

  async function setInspection(orderId, status) {
    for (const t of await orderComplianceTaskRepository.forOrder(orderId)) {
      if (t.task_type_name === shipmentGateService.INSPECTION_TASK) {
        await orderComplianceTaskRepository.setStatus(orderId, Number(t.task_type_id), status, status === 'skipped' ? 'Not applicable — test.' : null, admin.id);
      }
    }
  }

  async function taskStatus(orderId) {
    const t = (await orderComplianceTaskRepository.forOrder(orderId)).find((x) => x.task_type_name === shipmentGateService.INSPECTION_TASK);
    return t ? t.status : '';
  }

  async function stageStatus(orderId, stage) {
    const row = await db.queryOne('SELECT os.status FROM order_stages os JOIN stages_master sm ON sm.id = os.stage_id WHERE os.order_id = :o AND sm.stage_number = :s', { o: orderId, s: stage });
    return row ? row.status : null;
  }

  /** An order at Stage 7 (packing & BL) with its PL and BLI approved and a 2,700 balance expected. */
  async function orderAtBl(preset = 'Standard — New Buyer', inspection = false) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', preset);
    for (let stage = 1; stage <= 6; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    await createTestDocument(orderId, 'PL', 'approved');
    await createTestDocument(orderId, 'BLI', 'approved');
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute('UPDATE order_payment_status SET balance_amount = 2700 WHERE order_id = :o', { o: orderId });
    if (!inspection) {
      await setInspection(orderId, 'skipped');
    }
    return orderId;
  }

  const BL = { bl_number: 'MSCU7654321', bl_date: '2026-11-01' };
  const BALANCE = { balance_amount: '2700', balance_received_at: '2026-10-30', balance_exchange_rate: '91.5', balance_bank_reference: 'UTRGAP000001' };

  beforeAll(async () => {
    admin = await createTestUser('Admin');
    await companySettingsRepository.set('mail_sending_enabled', '0');
  });

  it('a before-shipment balance is cleared before the Bill of Lading, which waits for it', async () => {
    const orderId = await orderAtBl();
    expect(await shipmentGateService.blockers(orderId)).toEqual({ balance: shipmentGateService.MESSAGES.balance });
    expect(await call('recordBlIssued', orderId, BL)).toBe(`${shipmentGateService.MESSAGES.balance} Or release the step with a written reason.`);

    expect(await call('recordBalancePayment', orderId, BALANCE)).toContain('Balance remittance recorded.');
    expect(await call('clearBalancePayment', orderId, { balance_cleared_at: '2026-10-31' }))
      .toBe('Balance payment cleared before shipment. The Bill of Lading (Step 10f) can now be recorded; Step 11 completes once the Commercial Invoice is approved.');
    expect(await stageStatus(orderId, 8)).toBe('locked');
    expect(await call('clearBalancePayment', orderId)).toBe('The balance is already cleared.');

    expect(await shipmentGateService.blockers(orderId)).toEqual({});
    expect(await call('recordBlIssued', orderId, BL)).toBe('Bill of Lading recorded (MSCU7654321). Stage 8 (Commercial Invoice) unlocked.');

    expect(await call('clearBalancePayment', orderId)).toContain('Commercial Invoice');
    expect(await stageStatus(orderId, 8)).toBe('in_progress');
    await createTestDocument(orderId, 'CI', 'approved');
    expect(await call('clearBalancePayment', orderId)).toBe('Step 11 complete — the balance was cleared before shipment on 31 Oct 2026. Stage 9 (Document Despatch & Closure) unlocked.');
    expect(await stageStatus(orderId, 8)).toBe('gate_passed');
  });

  it('the balance is not open before the freight stage', async () => {
    const orderId = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 4; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    expect(await shipmentGateService.balanceOpenEarly(orderId)).toBe(false);
    expect(await call('recordBalancePayment', orderId, BALANCE)).toBe('Stage 8 has not been unlocked for this order yet.');
  });

  it('post-BL and Letter of Credit orders are unchanged', async () => {
    for (const preset of ['Established Buyer — Post-BL', 'Irrevocable Letter of Credit at Sight']) {
      const orderId = await orderAtBl(preset);
      expect(await shipmentGateService.balanceOpenEarly(orderId)).toBe(false);
      expect(await shipmentGateService.blockers(orderId)).toEqual({});
      expect(await call('recordBalancePayment', orderId, BALANCE)).toBe('Stage 8 has not been unlocked for this order yet.');
      expect(await call('recordBlIssued', orderId, BL)).toMatch(/^Bill of Lading recorded/);
    }
  });

  it('the inspection must pass before the Bill of Lading', async () => {
    const orderId = await orderAtBl('Established Buyer — Post-BL', true);
    expect(await shipmentGateService.inspectionApplies(orderId)).toBe(true);
    expect(await call('recordBlIssued', orderId, BL)).toBe(`${shipmentGateService.MESSAGES.inspection} Or release the step with a written reason.`);

    expect(await call('recordInspectionResult', orderId, { inspection_result: '', inspected_on: '2026-10-30', agency_name: 'SGS India' })).toBe('Choose whether the inspection passed or failed.');
    expect(await call('recordInspectionResult', orderId, { inspection_result: 'passed', inspected_on: '2099-01-01', agency_name: 'SGS India' })).toBe('Enter the date of the inspection (today or earlier).');
    expect(await call('recordInspectionResult', orderId, { inspection_result: 'failed', inspected_on: '2026-10-30', agency_name: 'SGS India', inspection_note: 'bad' })).toBe('Say what failed (at least 10 characters).');
    expect(await call('recordInspectionResult', orderId, { inspection_result: 'passed', inspected_on: '2026-10-30', agency_name: 'SGS India' })).toBe('Attach the inspection report (PDF or image).');

    const report = { buffer: Buffer.from('%PDF-1.4 inspection report'), originalname: 'report.pdf', mimetype: 'application/pdf' };
    expect(await call('recordInspectionResult', orderId, { inspection_result: 'failed', inspected_on: '2026-10-30', agency_name: 'SGS India', inspection_note: 'Two crates of slabs cracked.' }, admin, report))
      .toBe('Inspection recorded as FAILED. The managers were notified; the Bill of Lading waits for a Passed inspection.');
    expect(Object.keys(await shipmentGateService.blockers(orderId))).toEqual(['inspection']);
    expect(await call('recordInspectionResult', orderId, { inspection_result: 'passed', inspected_on: '2026-10-31', agency_name: 'SGS India' }, admin, report)).toBe('Inspection recorded as Passed.');
    expect(await shipmentGateService.blockers(orderId)).toEqual({});
    expect(await call('recordBlIssued', orderId, BL)).toMatch(/^Bill of Lading recorded/);
  });

  it('a failed inspection notifies the managers and a passed one resolves the checklist line', async () => {
    const orderId = await orderAtBl('Established Buyer — Post-BL', true);
    const md = await createTestUser('Managing Director');
    const file = await createTestFile(orderId, null);
    const order = await orderRepository.find(orderId);
    await shipmentGateService.saveInspection(order, 'failed', '2026-10-30', 'SGS India', file, 'Two crates cracked badly.', admin.id);
    const note = await db.queryOne('SELECT message FROM notifications WHERE user_id = :u AND related_order_id = :o ORDER BY id DESC LIMIT 1', { u: md.id, o: orderId });
    expect(note.message).toBe(`Pre-shipment inspection FAILED on order ${order.order_reference} (SGS India, 30 Oct 2026): Two crates cracked badly. The Bill of Lading waits for a Passed inspection.`);
    expect(await taskStatus(orderId)).toBe('pending_approval');
    const data = await showData(orderId);
    expect(data.inspection.result).toBe('failed');
    expect(data.shipmentGateBlockers).toEqual([shipmentGateService.MESSAGES.inspection]);
    expect(data.canOverrideShipmentGates).toBe(true);

    await shipmentGateService.saveInspection(order, 'passed', '2026-10-31', 'SGS India', file, null, admin.id);
    expect(await taskStatus(orderId)).toBe('approved');
    const n = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'INSPECTION_RESULT_RECORDED' AND entity_id = :o", { o: orderId });
    expect(Number(n.n)).toBe(2);
  });

  it('a manager releases the Bill of Lading step with a reason', async () => {
    const orderId = await orderAtBl('Standard — New Buyer', true);
    const exec = await createTestUser('Export Executive');
    expect(await call('recordBlIssued', orderId, BL, exec))
      .toBe(`${shipmentGateService.MESSAGES.balance} ${shipmentGateService.MESSAGES.inspection} A manager can release the step with a written reason.`);
    expect(await call('recordBlIssued', orderId, { ...BL, gate_override_reason: 'The buyer asked us to ship now.' }, exec))
      .toBe('Only a manager with "Override shipment gates" can release the Bill of Lading step.');

    expect(await call('recordBlIssued', orderId, { ...BL, gate_override_reason: 'short' })).toBe('Give the reason for releasing the Bill of Lading step (10 to 500 characters).');
    expect(await call('recordBlIssued', orderId, { ...BL, gate_override_reason: 'MD agreed by phone: long-standing buyer, balance arrives Monday.' })).toMatch(/^Bill of Lading recorded/);
    const row = await db.queryOne('SELECT gates, reason, user_id FROM order_shipment_gate_overrides WHERE order_id = :o', { o: orderId });
    expect([row.gates, row.reason, Number(row.user_id)]).toEqual(['balance,inspection', 'MD agreed by phone: long-standing buyer, balance arrives Monday.', admin.id]);
    const n = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'SHIPMENT_GATE_OVERRIDDEN' AND entity_id = :o", { o: orderId });
    expect(Number(n.n)).toBe(1);
    expect((await showData(orderId)).shipmentGateOverrides[0].gates_label).toBe('the pre-shipment balance and the inspection');

    for (const [name, expected] of [['Managing Director', true], ['Executive Director', true], ['Admin', true], ['Export Executive', false], ['Accounts Executive', false]]) {
      const u = await createTestUser(name);
      expect(!!(await permissionsOf(u.roleId)).override_shipment_gates).toBe(expected);
    }
  });

  it('the order page offers the early balance steps', async () => {
    const orderId = await orderAtBl();
    let data = await showData(orderId);
    expect([data.preShipmentBalance, data.balanceOpenEarly]).toEqual([true, true]);
    const source = fs.readFileSync(path.join(__dirname, '..', '..', 'views', 'orders', 'show.njk'), 'utf8');
    expect(source).toContain('This order\'s balance is due <strong>before shipment</strong>');
    expect(source).toContain('Mark Balance Cleared (before shipment)');
    expect(source).toContain('Complete Step 11 (unlocks Stage 9)');
    await call('recordBalancePayment', orderId, BALANCE);
    await call('clearBalancePayment', orderId);
    await call('recordBlIssued', orderId, BL);
    data = await showData(orderId);
    expect(data.payment.balance_cleared_at).not.toBeNull();
    expect(data.shipmentGateBlockers).toEqual([]);
  });

  it('with e-mail off, the buyer\'s 48 hours start when the OC is marked sent', async () => {
    const orderId = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 3; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    const docId = await createTestDocument(orderId, 'OC', 'approved');
    await emailDispatchService.markSentIfMailDisabled(docId);
    expect((await db.queryOne('SELECT status FROM documents WHERE id = :id', { id: docId })).status).toBe('sent');
    expect(await orderOcAcknowledgmentRepository.find(orderId)).toBeNull(); // approval alone does not start the clock

    const message = await call('markOcSentToBuyer', orderId);
    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    expect(ack).not.toBeNull();
    expect(new Date(String(ack.due_at).replace(' ', 'T')) - new Date(String(ack.sent_at).replace(' ', 'T'))).toBe(48 * 3600 * 1000);
    expect(message).toMatch(/^Order Confirmation marked as sent to the buyer\. If the buyer does not respond by /);
    expect(await call('markOcSentToBuyer', orderId)).toBe('This Order Confirmation is already marked as sent to the buyer.');

    await companySettingsRepository.set('mail_sending_enabled', '1');
    expect(await call('markOcSentToBuyer', orderId)).toBe('E-mail sending is on: the buyer\'s 48 hours start when the Order Confirmation is e-mailed.');
    await companySettingsRepository.set('mail_sending_enabled', '0');
  });
});
