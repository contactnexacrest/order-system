'use strict';

const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const clientPortalController = require('../../src/controllers/clientPortalController');
const notificationRepository = require('../../src/repositories/notificationRepository');
const orderCancellationRepository = require('../../src/repositories/orderCancellationRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of DisputeAndCancellationNotificationTest.php. External QA report
 * N-07: raising a dispute from the client portal and requesting/approving/
 * rejecting an order cancellation only ever wrote the audit log (the
 * catch-all notification email) — the specific people actually
 * responsible for acting on each (manage_disputes holders, edit_locked_data
 * holders) and the original requester on a cancellation decision never
 * got a targeted in-app notification, unlike submitFeedback()'s own
 * dual-channel pattern for manage_orders.
 */
describe('Dispute and cancellation notifications (External QA report N-07)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Export Executive') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-dacn-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeReq(orderId, body = {}, extraParams = {}) {
    return {
      params: { id: String(orderId), ...extraParams },
      body,
      session: {},
      permissions: new Proxy({}, { get: () => true }),
    };
  }

  function fakeRes() {
    const res = { statusCode: 200 };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = () => {};
    res.redirect = () => {};
    return res;
  }

  it('raising a dispute notifies every manage_disputes holder', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute('UPDATE orders SET dispute_button_visible_to_client = 1 WHERE id = :id', { id: orderId });
    const disputeManager = await createTestUser('Admin');
    const noPermissionUser = await createTestUser('Export Executive');
    const req = fakeReq(orderId, { description: 'The goods arrived with visible damage to the packaging.' });
    req.session._client_portal_client_id = clientId;

    await clientPortalController.raiseDispute(req, fakeRes());

    const notifications = await notificationRepository.forUser(disputeManager);
    expect(notifications.length).toBeGreaterThan(0);
    expect(notifications[0].type).toBe('dispute_raised');
    expect(Number(notifications[0].related_order_id)).toBe(orderId);

    expect(await notificationRepository.forUser(noPermissionUser)).toEqual([]);
  });

  it('requesting a cancellation notifies every edit_locked_data holder', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser('Export Executive');
    const approver = await createTestUser('Admin');
    const req = fakeReq(orderId, { reason: 'Buyer has gone unresponsive for over a month.' });
    req.user = { id: requester };

    await ordersController.requestCancellation(req, fakeRes());

    const notifications = await notificationRepository.forUser(approver);
    expect(notifications.length).toBeGreaterThan(0);
    expect(notifications[0].type).toBe('order_cancellation_requested');
    expect(Number(notifications[0].related_order_id)).toBe(orderId);
  });

  it('approving a cancellation notifies the original requester', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser('Export Executive');
    const approver = await createTestUser('Admin');
    const cancellationId = await orderCancellationRepository.create(orderId, 'Buyer requested cancellation before shipment.', requester);
    const req = fakeReq(orderId, { refund_status: 'not_applicable' }, { cancellationId: String(cancellationId) });
    req.user = { id: approver };

    await ordersController.approveCancellation(req, fakeRes());

    const notifications = await notificationRepository.forUser(requester);
    expect(notifications.length).toBeGreaterThan(0);
    expect(notifications[0].type).toBe('order_cancellation_approved');
  });

  it('rejecting a cancellation notifies the original requester', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser('Export Executive');
    const approver = await createTestUser('Admin');
    const cancellationId = await orderCancellationRepository.create(orderId, 'Buyer requested cancellation before shipment.', requester);
    const req = fakeReq(orderId, { decision_notes: 'Buyer confirmed by phone they want to proceed after all.' }, { cancellationId: String(cancellationId) });
    req.user = { id: approver };

    await ordersController.rejectCancellation(req, fakeRes());

    const notifications = await notificationRepository.forUser(requester);
    expect(notifications.length).toBeGreaterThan(0);
    expect(notifications[0].type).toBe('order_cancellation_rejected');
  });
});
