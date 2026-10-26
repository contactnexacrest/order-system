'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const emailService = require('../../src/services/emailService');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const notificationRepository = require('../../src/repositories/notificationRepository');
const orderAssigneeRepository = require('../../src/repositories/orderAssigneeRepository');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const buyerActionService = require('../../src/services/buyerActionService');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of AuditLogSendsNoEmailTest.php. Owner decision (2026-10-26): "we
 * don't need mail on each audit step/event/log". auditLogRepository.log()
 * used to email every logged action (order assignees + every Admin/MD/ED +
 * a catch-all) — 90+ mails for a single order, so the few that needed
 * someone to act were lost in the noise. The audit log is now only a
 * record; mail goes out only where a specific person needs to know or act
 * (notificationRepository.create()). The client actions that used to reach
 * staff only through that hook now notify the order's own team directly.
 */
describe('Audit log sends no email (owner decision 2026-10-26)', () => {
  let sendSpy;

  beforeEach(() => {
    sendSpy = jest.spyOn(emailService, 'sendPlainText').mockResolvedValue(true);
  });

  afterEach(() => {
    sendSpy.mockRestore();
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

  it('logs an order action without sending any email', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderAssigneeRepository.add(orderId, await createTestUser('Export Executive'), null);

    for (const actionType of ['ORDER_STAGE_GATE_PASSED', 'DOCUMENT_APPROVED', 'ADVANCE_PAYMENT_CLEARED', 'REVIEWERS_ASSIGNED']) {
      await auditLogRepository.log(null, actionType, 'orders', orderId);
    }

    expect(sendSpy).not.toHaveBeenCalled();
    const source = fs.readFileSync(path.join(__dirname, '../../src/repositories/auditLogRepository.js'), 'utf8');
    expect(source).not.toMatch(/require\([^)]*(emailService|notificationRepository|actionNotificationService)|sendPlainText\(/);
  });

  it('notifies only the order\'s own team when the client acts on it', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const assigneeId = await createTestUser('Export Executive');
    await orderAssigneeRepository.add(orderId, assigneeId, null);

    const notified = await notificationRepository.notifyOrderTeam(orderId, 'buyer_oc_acknowledged', 'The buyer acknowledged the Order Confirmation.');

    expect(notified).toEqual([assigneeId]);
    expect(sendSpy).toHaveBeenCalledTimes(1);
    expect(sendSpy.mock.calls[0][1]).toBe('NexaCrest — The buyer acknowledged the Order Confirmation.');
  });

  it('falls back to everyone who manages orders when nobody is assigned', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const notified = await notificationRepository.notifyOrderTeam(orderId, 'client_payment_reported', 'The buyer reported a payment.');

    expect(notified.length).toBeGreaterThan(0);
    expect(new Set(notified).size).toBe(notified.length);
  });

  it('tells the order team when the buyer acknowledges the OC through their link', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const assigneeId = await createTestUser('Export Executive');
    await orderAssigneeRepository.add(orderId, assigneeId, null);
    for (let stage = 1; stage <= 3; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    const documentId = await createTestDocument(orderId, 'OC', 'sent');
    await orderOcAcknowledgmentRepository.recordSent(orderId, documentId, new Date(), new Date(Date.now() + 48 * 3600 * 1000));

    const result = await buyerActionService.acknowledgeOc(orderId);

    expect(result.ok).toBe(true);
    const rows = (await notificationRepository.forUser(assigneeId)).filter((n) => n.type === 'buyer_oc_acknowledged');
    expect(rows).toHaveLength(1);
  });

  it('puts the notification itself in the email subject, trimmed to fit', () => {
    expect(notificationRepository.emailSubject('Amendment AMD-1 needs MD approval.')).toBe('NexaCrest — Amendment AMD-1 needs MD approval.');
    const long = notificationRepository.emailSubject('x'.repeat(200));
    expect([...long].length).toBe('NexaCrest — '.length + 108);
    expect(long.endsWith('…')).toBe(true);
  });
});
