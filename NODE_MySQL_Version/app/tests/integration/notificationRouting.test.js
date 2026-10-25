'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const userRepository = require('../../src/repositories/userRepository');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const actionNotificationService = require('../../src/services/actionNotificationService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of NotificationRoutingTest.php. Notification Routing Plan:
 * actionNotificationService.notify() must route each action type to
 * exactly one of five categories (order / target / broadcast / self /
 * none), per the user-approved classification — replacing the original
 * flat "catch-all + opted-in actor" design that fired identically for
 * every one of 230 action types regardless of what actually happened.
 */
describe('Notification Routing Plan', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    await companySettingsRepository.set('notification_email', '');
  });

  afterEach(async () => {
    await companySettingsRepository.set('notification_email', '');
  });

  async function createTestUser(roleName = 'Export Executive') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-notif-routing-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function insertTestDocumentForOrder(orderId) {
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, generated_by, generated_at)
       SELECT :order_id, id, 'JEST-NOTIF-TEST-DOC', NULL, NOW() FROM document_types LIMIT 1`,
      { order_id: orderId }
    );
    return result.insertId;
  }

  // ---------------------------------------------------------------
  // Category classification itself
  // ---------------------------------------------------------------

  it('categoryFor classifies each of the five categories', () => {
    expect(actionNotificationService.categoryFor('ORDER_CREATED')).toBe('order');
    expect(actionNotificationService.categoryFor('USER_PERMISSION_GRANTED')).toBe('target');
    expect(actionNotificationService.categoryFor('PORT_ADDED')).toBe('broadcast');
    expect(actionNotificationService.categoryFor('ROLE_CREATED')).toBe('broadcast');
    expect(actionNotificationService.categoryFor('SAMPLE_DATA_LOADED')).toBe('none');
    expect(actionNotificationService.categoryFor('PASSWORD_CHANGED')).toBe('self');
    expect(actionNotificationService.categoryFor('SOME_FUTURE_ACTION_TYPE_NOBODY_CLASSIFIED_YET')).toBe('self');
  });

  it('categoryFor routes field-protection unlocks by entity type', () => {
    expect(actionNotificationService.categoryFor('PROTECTED_FIELD_UNLOCKED', 'payment_presets')).toBe('broadcast');
    expect(actionNotificationService.categoryFor('PROTECTED_FIELD_UNLOCKED', 'company_settings')).toBe('broadcast');
  });

  // ---------------------------------------------------------------
  // Order category — assignees + oversight + catch-all, never toggle-gated
  // ---------------------------------------------------------------

  it('order category notifies assignees even when their self toggle is off', async () => {
    const assigneeId = await createTestUser('Export Executive');
    await userRepository.updateNotificationPreference(assigneeId, false);
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, assigneeId);
    const assignee = await userRepository.findById(assigneeId);

    const actorId = await createTestUser('Logistics Executive');
    const recipients = await actionNotificationService.notify(actorId, 'ORDER_STAGE_GATE_PASSED', 'orders', orderId, null, null, null, null);

    expect(recipients).toContain(assignee.email);
  });

  it('order category also notifies oversight as a safety net', async () => {
    const adminId = await createTestUser('Admin');
    const admin = await userRepository.findById(adminId);
    // No assignedTo passed — createTestOrder() leaves this order with
    // zero assignees.
    const orderId = await createTestOrder(await createTestClient());

    const recipients = await actionNotificationService.notify(null, 'BL_ISSUED', 'orders', orderId, null, null, null, null);

    expect(recipients).toContain(admin.email);
  });

  it('order category resolves a child row back to its owning order one-hop', async () => {
    const assigneeId = await createTestUser('Export Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, assigneeId);
    const assignee = await userRepository.findById(assigneeId);

    const documentId = await insertTestDocumentForOrder(orderId);

    const recipients = await actionNotificationService.notify(assigneeId, 'DOCUMENT_APPROVED', 'documents', documentId, null, null, null, null);

    expect(recipients).toContain(assignee.email);
  });

  // ---------------------------------------------------------------
  // Target category — the specific affected person, never toggle-gated
  // ---------------------------------------------------------------

  it('target category notifies the affected user even with their toggle off', async () => {
    const targetId = await createTestUser('Export Executive');
    await userRepository.updateNotificationPreference(targetId, false);
    const target = await userRepository.findById(targetId);
    const adminId = await createTestUser('Admin');

    const recipients = await actionNotificationService.notify(adminId, 'PASSWORD_ADMIN_RESET', 'users', targetId, null, null, null, null);

    expect(recipients).toEqual([target.email]);
  });

  it('target category grant uses the explicit hint since entity_id is the grant row, not the user', async () => {
    const targetId = await createTestUser('Export Executive');
    const target = await userRepository.findById(targetId);

    const recipients = await actionNotificationService.notify(null, 'USER_PERMISSION_GRANTED', 'user_permissions', 999999, null, null, null, null, targetId);

    expect(recipients).toEqual([target.email]);
  });

  it('target category archive also notifies oversight', async () => {
    const targetId = await createTestUser('Export Executive');
    const target = await userRepository.findById(targetId);
    const adminId = await createTestUser('Admin');
    const admin = await userRepository.findById(adminId);

    const recipients = await actionNotificationService.notify(null, 'USER_ARCHIVED', 'users', targetId, null, null, null, null);

    expect(recipients).toContain(target.email);
    expect(recipients).toContain(admin.email);
  });

  // ---------------------------------------------------------------
  // Broadcast category — permission-key holders, never toggle-gated
  // ---------------------------------------------------------------

  it('broadcast category notifies everyone holding the governing permission', async () => {
    const exportExecId = await createTestUser('Export Executive');
    await userRepository.updateNotificationPreference(exportExecId, false);
    const exportExec = await userRepository.findById(exportExecId);
    const viewerId = await createTestUser('Viewer / Auditor');
    const viewer = await userRepository.findById(viewerId);

    const recipients = await actionNotificationService.notify(exportExecId, 'PORT_ADDED', 'ports', 1, null, null, null, null);

    expect(recipients).toContain(exportExec.email);
    expect(recipients).not.toContain(viewer.email);
  });

  it('broadcast category routes oversight-only actions to Admin/MD/ED', async () => {
    const adminId = await createTestUser('Admin');
    const admin = await userRepository.findById(adminId);
    const exportExecId = await createTestUser('Export Executive');
    const exportExec = await userRepository.findById(exportExecId);

    const recipients = await actionNotificationService.notify(null, 'ROLE_CREATED', 'roles', 1, null, null, null, null);

    expect(recipients).toContain(admin.email);
    expect(recipients).not.toContain(exportExec.email);
  });

  // ---------------------------------------------------------------
  // None category — audit row written, but zero email ever attempted
  // ---------------------------------------------------------------

  it('none category action types send no email even with a catch-all configured', async () => {
    await companySettingsRepository.set('notification_email', 'ops-notify@nexacrestinternational.test');
    const userId = await createTestUser();

    await auditLogRepository.log(userId, 'SAMPLE_DATA_LOADED', 'users', userId);
    const direct = await actionNotificationService.notify(userId, 'SAMPLE_DATA_LOADED', 'users', userId, null, null, null, null);

    expect(direct).toEqual([]);

    const log = await db.queryOne("SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'SAMPLE_DATA_LOADED'");
    expect(Number(log.c)).toBeGreaterThan(0);
  });
});
