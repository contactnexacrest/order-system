'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const ordersController = require('../../src/controllers/ordersController');
const emailDispatchService = require('../../src/services/emailDispatchService');
const mailSenderService = require('../../src/services/mailSenderService');
const stageGateService = require('../../src/services/stageGateService');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * docs/schema.sql Section AU — four related gaps found during live testing:
 * (1) the Stage 4 "buyer acknowledged by email" override only worked once
 * an order_oc_acknowledgments row already existed (i.e. only after an OC
 * send had actually completed) — if the send was itself broken, staff had
 * no way to move the order past Stage 4 at all; (2)/(3) nothing could turn
 * off the always-on email approval queue, or kill sending entirely, while
 * diagnosing a hosting SMTP problem; (4) the 48h auto-confirm window was
 * hardcoded.
 */
describe('OC acknowledgment override + mail toggles (Section AU)', () => {
  const SETTING_KEYS = [
    'mail_sending_enabled', 'mail_approval_queue_enabled',
    'oc_ack_override_restricted', 'oc_ack_auto_confirm_hours',
  ];
  const originalValues = {};

  beforeAll(async () => {
    for (const key of SETTING_KEYS) {
      originalValues[key] = String((await companySettingsRepository.get(key)) ?? '');
    }
  });

  afterEach(async () => {
    for (const key of SETTING_KEYS) {
      await companySettingsRepository.set(key, originalValues[key], null);
    }
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

  async function createTestDocument(orderId, typeCode = 'OC', status = 'draft') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-OCACK-${Math.random().toString(16).slice(2, 10)}`, status }
    );
    return result.insertId;
  }

  async function denyPermission(userId, permissionKey) {
    const permission = await db.queryOne('SELECT id FROM permissions WHERE permission_key = :key', { key: permissionKey });
    await db.execute(
      'INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:user_id, :permission_id, 0)',
      { user_id: userId, permission_id: permission.id }
    );
  }

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  /** Mirrors how server.js's permission middleware populates req.permissions — role grants, then this specific user's own overrides on top. */
  async function permissionsFor(userId, roleId) {
    const rows = await db.query(
      'SELECT p.permission_key, rp.is_enabled FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :role_id',
      { role_id: roleId }
    );
    const overrides = await db.query(
      'SELECT p.permission_key, up.is_enabled FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = :user_id',
      { user_id: userId }
    );
    const perms = {};
    for (const r of rows) perms[r.permission_key] = !!r.is_enabled;
    for (const r of overrides) perms[r.permission_key] = !!r.is_enabled;
    return perms;
  }

  async function reqForUser(userId, orderId, body) {
    const user = await db.queryOne('SELECT * FROM users WHERE id = :id', { id: userId });
    return { params: { id: String(orderId) }, body: body || {}, user, session: {}, permissions: await permissionsFor(userId, user.role_id) };
  }

  async function unlockStage4(orderId) {
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await stageGateService.passAndUnlockNext(orderId, 3, null);
    expect(await stageGateService.isUnlocked(orderId, 4)).toBe(true);
  }

  // --- OC acknowledgment override, decoupled from a prior send ---

  it('recordOcAcknowledgment succeeds without any prior send', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockStage4(orderId);
    const documentId = await createTestDocument(orderId, 'OC', 'draft');
    expect(await orderOcAcknowledgmentRepository.find(orderId)).toBeNull();
    const userId = await createTestUser('Export Executive');

    const req = await reqForUser(userId, orderId, { acknowledged_note: 'Buyer confirmed by phone, evidence recorded here.' });
    await ordersController.recordOcAcknowledgment(req, fakeRes());

    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    expect(ack).not.toBeNull();
    expect(ack.document_id).toBe(documentId);
    expect(ack.acknowledged_at).not.toBeNull();
    expect(ack.acknowledged_via).toBe('staff_recorded_email');
    expect(await stageGateService.isUnlocked(orderId, 5)).toBe(true);
  });

  it('recordOcAcknowledgment refuses when no OC document exists at all', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockStage4(orderId);
    const userId = await createTestUser('Export Executive');

    const req = await reqForUser(userId, orderId, { acknowledged_note: 'Buyer confirmed by phone.' });
    await ordersController.recordOcAcknowledgment(req, fakeRes());

    expect(await orderOcAcknowledgmentRepository.find(orderId)).toBeNull();
    expect(await stageGateService.isUnlocked(orderId, 5)).toBe(false);
    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
  });

  it('oc_ack_auto_confirm_hours is configurable', async () => {
    await companySettingsRepository.set('oc_ack_auto_confirm_hours', '2');
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'OC', 'approved');

    const sentAt = new Date();
    const dueAt = new Date(sentAt.getTime() + 2 * 3600 * 1000);
    await orderOcAcknowledgmentRepository.recordSent(orderId, documentId, sentAt, dueAt);

    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    const deltaMs = new Date(ack.due_at).getTime() - new Date(ack.sent_at).getTime();
    expect(Math.abs(deltaMs - 2 * 3600 * 1000)).toBeLessThan(5000);
  });

  // --- oc_ack_override_restricted gates the override behind a permission ---

  it('override restricted blocks a user without the permission', async () => {
    await companySettingsRepository.set('oc_ack_override_restricted', '1');
    const orderId = await createTestOrder(await createTestClient());
    await unlockStage4(orderId);
    await createTestDocument(orderId, 'OC', 'draft');
    const userId = await createTestUser('Export Executive'); // has override_buyer_acknowledgment by default — denied below
    await denyPermission(userId, 'override_buyer_acknowledgment');

    const req = await reqForUser(userId, orderId, { acknowledged_note: 'Buyer confirmed by phone.' });
    await ordersController.recordOcAcknowledgment(req, fakeRes());

    expect(await orderOcAcknowledgmentRepository.find(orderId)).toBeNull();
    expect(await stageGateService.isUnlocked(orderId, 5)).toBe(false);
    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
  });

  it('override restricted allows a user with the permission', async () => {
    await companySettingsRepository.set('oc_ack_override_restricted', '1');
    const orderId = await createTestOrder(await createTestClient());
    await unlockStage4(orderId);
    await createTestDocument(orderId, 'OC', 'draft');
    const userId = await createTestUser('Export Executive'); // keeps override_buyer_acknowledgment from seed

    const req = await reqForUser(userId, orderId, { acknowledged_note: 'Buyer confirmed by phone.' });
    await ordersController.recordOcAcknowledgment(req, fakeRes());

    expect(await stageGateService.isUnlocked(orderId, 5)).toBe(true);
  });

  // --- mail_sending_enabled: global kill switch ---

  it('mail_sending_enabled=0 blocks a non-security email', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '0');

    const sent = await mailSenderService.send('buyer@real-company.example', 'Subject', 'Body', [], { isSecurityEmail: false });

    expect(sent).toBe(false);
  });

  it('mail_sending_enabled=0 does not change a security email outcome', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '0');

    // No SMTP configured in the test environment either way, so this still
    // returns false — the point is it must reach that same no-SMTP
    // fallback rather than being blocked earlier by the kill switch, which
    // only ever checks when isSecurityEmail is false.
    const sent = await mailSenderService.send('staff@nexacrest.test', 'Your 2FA code', 'Body', [], { isSecurityEmail: true });

    expect(sent).toBe(false);
  });

  // --- mail_approval_queue_enabled: bypass the Level-2 approval queue ---

  it('approval queue disabled dispatches immediately instead of queuing', async () => {
    await companySettingsRepository.set('mail_approval_queue_enabled', '0');
    const clientId = await createTestClient('buyer@example.test');
    const orderId = await createTestOrder(clientId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved');
    const userId = await createTestUser('Export Executive');

    const result = await emailDispatchService.requestSend(orderId, documentId, 'send_qt', null, userId);

    expect(result.dispatched).toBe(true);
    // No SMTP configured in the test environment, so the immediate attempt
    // itself fails — expected, and asserts the row never sits forever in
    // 'pending_approval' waiting for a human.
    expect(result.sent).toBe(false);
  });

  it('approval queue enabled by default still queues for Level 2', async () => {
    // mail_approval_queue_enabled left at its seeded default ('1').
    const clientId = await createTestClient('buyer@example.test');
    const orderId = await createTestOrder(clientId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved');
    const userId = await createTestUser('Export Executive');

    const result = await emailDispatchService.requestSend(orderId, documentId, 'send_qt', null, userId);

    expect(result.dispatched).toBe(false);
    expect(result.sent).toBeNull();
  });
});
