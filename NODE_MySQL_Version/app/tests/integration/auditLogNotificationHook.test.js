'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const userRepository = require('../../src/repositories/userRepository');
const actionNotificationService = require('../../src/services/actionNotificationService');
const disputeRepository = require('../../src/repositories/disputeRepository');
const orderCancellationRepository = require('../../src/repositories/orderCancellationRepository');
const orderComplaintRepository = require('../../src/repositories/orderComplaintRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of AuditLogNotificationHookTest.php. User-reported batch item 20
 * ("non-negotiable"): auditLogRepository.log() — the single choke point
 * every meaningful action in this codebase already funnels through
 * (47+ call sites) — must fire an action notification every time it's
 * called, to the configurable catch-all notification_email and to the
 * acting user's own email when opted in.
 */
describe('auditLogRepository notification hook (90-item backlog item 20, non-negotiable)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Export Executive') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-notif-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  it('auditLogRepository is wired to call actionNotificationService', () => {
    const source = fs.readFileSync(path.join(__dirname, '../../src/repositories/auditLogRepository.js'), 'utf8');
    expect(source).toContain('actionNotificationService.notify(');
  });

  it('notify resolves the configured catch-all address against real DB state', async () => {
    await companySettingsRepository.set('notification_email', 'ops-notify@nexacrestinternational.test');
    const userId = await createTestUser();
    await userRepository.updateNotificationPreference(userId, false);

    const recipients = await actionNotificationService.notify(userId, 'FIELD_EDIT', 'orders', 1, 'incoterm_id', '1', '2', null);

    expect(recipients).toEqual(['ops-notify@nexacrestinternational.test']);

    await companySettingsRepository.set('notification_email', '');
  });

  it("notify includes both the catch-all and an opted-in user's address", async () => {
    await companySettingsRepository.set('notification_email', 'ops-notify@nexacrestinternational.test');
    const userId = await createTestUser();
    await userRepository.updateNotificationPreference(userId, true);
    const user = await userRepository.findById(userId);

    const recipients = await actionNotificationService.notify(userId, 'ORDER_CREATED', 'orders', 1, null, null, null, null);

    expect(recipients).toContain('ops-notify@nexacrestinternational.test');
    expect(recipients).toContain(user.email);

    await companySettingsRepository.set('notification_email', '');
  });

  // External QA report L-46: resolveEntityReference() is the DB-touching
  // half of the subject-readability fix — see actionNotificationService's
  // buildMessage() unit tests for the pure-helper half.
  it("resolveEntityReference returns the order's own reference number", async () => {
    const orderId = await createTestOrder(await createTestClient());

    const resolved = await actionNotificationService.resolveEntityReference('orders', orderId);

    expect(resolved).not.toBeNull();
    expect(resolved.label).toBe('Order');
    expect(resolved.reference).not.toBe('');
  });

  it('resolveEntityReference returns null for an unmapped entity type', async () => {
    expect(await actionNotificationService.resolveEntityReference('watermark_settings', 1)).toBeNull();
  });

  it('resolveEntityReference returns null for an id that no longer exists', async () => {
    expect(await actionNotificationService.resolveEntityReference('orders', 999999999)).toBeNull();
  });

  /**
   * External QA report N-10: 'order_lc_details' is logged with the
   * ORDER's own id as entity_id (L-23's "one row per order" convention),
   * so this must resolve against orders.order_reference directly —
   * never order_lc_details' own (nonexistent, since no row was ever
   * created here) id.
   */
  it("resolveEntityReference resolves order_lc_details to the order's own reference", async () => {
    const orderId = await createTestOrder(await createTestClient());

    const resolved = await actionNotificationService.resolveEntityReference('order_lc_details', orderId);

    expect(resolved).not.toBeNull();
    expect(resolved.label).toBe('Order');
    expect(resolved.reference).not.toBe('');
  });

  /**
   * External QA report N-10: 'disputes'/'order_cancellations'/
   * 'order_complaints' are logged with the CHILD row's own id as
   * entity_id — these must join back to the order that owns it.
   */
  it('resolveEntityReference joins disputes back to their owning order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const disputeId = await disputeRepository.create(orderId, '2026-10-23', 'Buyer', 'N-10 test dispute', null, '2026-11-05');

    const resolved = await actionNotificationService.resolveEntityReference('disputes', disputeId);

    expect(resolved).not.toBeNull();
    expect(resolved.label).toBe('Dispute');
    expect(resolved.reference).toMatch(/^Order /);
  });

  it('resolveEntityReference joins order_cancellations back to their owning order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser();
    const cancellationId = await orderCancellationRepository.create(orderId, 'N-10 test cancellation request.', requester);

    const resolved = await actionNotificationService.resolveEntityReference('order_cancellations', cancellationId);

    expect(resolved).not.toBeNull();
    expect(resolved.label).toBe('Cancellation');
    expect(resolved.reference).toMatch(/^Order /);
  });

  it('resolveEntityReference joins order_complaints back to their owning order', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintRepository.create(orderId, clientId, 'N-10 test complaint.');

    const resolved = await actionNotificationService.resolveEntityReference('order_complaints', complaintId);

    expect(resolved).not.toBeNull();
    expect(resolved.label).toBe('Complaint');
    expect(resolved.reference).toMatch(/^Order /);
  });

  it('calling log does not throw when notifications are fully configured', async () => {
    await companySettingsRepository.set('notification_email', 'ops-notify@nexacrestinternational.test');
    const userId = await createTestUser();

    await auditLogRepository.log(userId, 'TEST_ACTION', 'users', userId, null, null, null, null);

    const entries = await auditLogRepository.search({ userId, actionType: 'TEST_ACTION' });
    expect(entries.length).toBeGreaterThan(0);

    await companySettingsRepository.set('notification_email', '');
  });
});
